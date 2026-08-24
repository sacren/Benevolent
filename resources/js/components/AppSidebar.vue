<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    BookOpen,
    FolderGit2,
    Funnel,
    LayoutGrid,
    Send,
    UserPlus,
    Users,
} from '@lucide/vue';
import { computed } from 'vue';
import AppLogo from '@/components/AppLogo.vue';
import NavFooter from '@/components/NavFooter.vue';
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { usePermissions } from '@/composables/usePermissions';
import { dashboard } from '@/routes';
import { index as blasts } from '@/routes/blasts';
import { create as inviteOperator } from '@/routes/operators/invitations';
import { index as segments } from '@/routes/segments';
import { index as supporters } from '@/routes/supporters';
import type { NavItem } from '@/types';

const { can } = usePermissions();

const campaignNavItems: NavItem[] = [
    {
        title: 'Dashboard',
        href: dashboard(),
        icon: LayoutGrid,
    },
    {
        title: 'Supporters',
        href: supporters(),
        icon: Users,
    },
    {
        title: 'Blasts',
        href: blasts(),
        icon: Send,
    },
    {
        title: 'Segments',
        href: segments(),
        icon: Funnel,
    },
];

/*
 * Offered only to somebody who may act on it. Hiding it is a courtesy rather
 * than the defence -- OperatorInvitationPolicy refuses the request whatever the
 * sidebar rendered -- but a link that answers 403 to every Staff operator who
 * clicks it is a control the product should not have shown them.
 */
const mainNavItems = computed<NavItem[]>(() =>
    can('manage-operators')
        ? [
              ...campaignNavItems,
              {
                  title: 'Invite an operator',
                  href: inviteOperator(),
                  icon: UserPlus,
              },
          ]
        : campaignNavItems,
);

const footerNavItems: NavItem[] = [
    {
        title: 'Repository',
        href: 'https://github.com/laravel/vue-starter-kit',
        icon: FolderGit2,
    },
    {
        title: 'Documentation',
        href: 'https://laravel.com/docs/starter-kits#vue',
        icon: BookOpen,
    },
];
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link :href="dashboard()">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent>
            <NavMain :items="mainNavItems" />
        </SidebarContent>

        <SidebarFooter>
            <NavFooter :items="footerNavItems" />
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
