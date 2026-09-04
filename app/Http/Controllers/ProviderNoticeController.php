<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\MailOutcomes\RecordNotice;
use App\MailOutcomes\SigningCertificates;
use Aws\Sns\Exception\InvalidSnsMessageException;
use Aws\Sns\Message;
use Aws\Sns\MessageValidator;
use Illuminate\Http\Client\HttpClientException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;

/**
 * Where SNS delivers the mail provider's notices (D-85, D-88).
 *
 * **Every message is verified before anything else reads it.** The body is
 * parsed from the raw request rather than from `php://input` or a content type,
 * neither of which the captures recorded; AWS's validator then checks the
 * signature against the certificate it names, and the application checks the
 * topic, which the validator does not. Anything that fails either is answered
 * 403 and writes nothing. SNS does not retry a 403, which is right: a message
 * that cannot be verified now will not verify later.
 *
 * **A refusal costs a comparison, and leaves nothing behind (D-90).** The
 * validator fetches the certificate a message names *before* it can check the
 * signature, so before this ordering anybody could make the application fetch
 * a URL of their choosing from AWS and write a reported failure with a stack
 * trace to the log -- about 9 KB a request, measured at Step 4. So the topic
 * is compared first: it is one of the signed fields, so reading it before the
 * signature decides nothing, and a message naming another topic is refused
 * before any fetch. The certificate's host is pinned to the topic's own
 * region, so the validator refuses every other AWS region's host before
 * fetching. A certificate AWS answers with a 4xx does not exist, and a message
 * signed with it is a forgery, so it is refused like one and not reported.
 * **What remains:** somebody who knows the topic's ARN still gets one silent
 * fetch from the pinned host per request.
 *
 * **A Type the validator does not know is refused with the rest.** It has no
 * signed-field list for one, so it cannot be verified, and an unverified
 * message is not known to be from SNS at all.
 *
 * **Answered 2xx only after the write has committed (D-88).** A write that
 * fails is a 5xx, and so is a certificate AWS could not serve in time, and SNS
 * retries both under the topic's policy.
 *
 * **A subscription is confirmed by the user, never by visiting
 * `SubscribeURL`** (D-85): a confirmation made here would leave the
 * subscription open to an unauthenticated unsubscribe. The one thing this
 * endpoint does with a confirmation is put its Token where the user can read
 * it -- the application log, the only place a request body reaches, since the
 * web server does not record bodies. The token can only confirm a subscription
 * that delivers here, confirming needs the topic owner's AWS sign-in, and it
 * expires after two days.
 *
 * Reached only on a central host, and outside the `web` middleware group,
 * because SNS carries no session, no cookie and no CSRF token: see
 * routes/web.php.
 */
final class ProviderNoticeController extends Controller
{
    public function __invoke(Request $request, SigningCertificates $certificates, RecordNotice $recordNotice): Response
    {
        $topic = config('services.ses.notifications_topic_arn');

        if (! is_string($topic) || $topic === '') {
            return response()->noContent(403);
        }

        try {
            $message = Message::fromJsonString($request->getContent());

            if ($message['TopicArn'] !== $topic) {
                return response()->noContent(403);
            }

            (new MessageValidator($certificates, $this->certificateHostPattern($topic)))->validate($message);
        } catch (RequestException $exception) {
            if ($exception->response->clientError()) {
                return response()->noContent(403);
            }

            report($exception);

            return response()->noContent(503);
        } catch (HttpClientException $exception) {
            report($exception);

            return response()->noContent(503);
        } catch (InvalidSnsMessageException|InvalidArgumentException|RuntimeException) {
            return response()->noContent(403);
        }

        if ($message['Type'] === 'SubscriptionConfirmation') {
            Log::info('SNS asked to confirm a subscription to the notices topic. Confirm it from the desktop with this token: '.$message['Token']);
        }

        if ($message['Type'] === 'Notification') {
            $notice = json_decode((string) $message['Message'], true);

            if (is_array($notice)) {
                $recordNotice($notice);
            }
        }

        return response()->noContent(200);
    }

    /**
     * The one host a certificate for $topic can come from: SNS in the topic's
     * own region, the fourth field of its ARN (`arn:aws:sns:<region>:…`).
     *
     * An ARN with no region field gives a pattern no host matches, so every
     * message is refused, as an unset topic already does.
     */
    private function certificateHostPattern(string $topic): string
    {
        $region = explode(':', $topic)[3] ?? '';

        return '/^sns\.'.preg_quote($region, '/').'\.amazonaws\.com$/';
    }
}
