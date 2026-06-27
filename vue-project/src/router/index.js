import { createRouter, createWebHashHistory } from 'vue-router'
import { useUserStore } from '@/stores/user'

import Layout from '@/components/Layout.vue'

const routes = [
  { path: '/login', name: 'Login', component: () => import('@/views/Login.vue'), meta: { guest: true } },
  { path: '/share/:token', name: 'SharedNote', component: () => import('@/views/SharedNote.vue') },
  {
    path: '/',
    component: Layout,
    meta: { requiresAuth: true },
    children: [
      { path: '', name: 'Notes', component: () => import('@/views/Notes.vue') },
      { path: 'note/:id', name: 'NoteEditor', component: () => import('@/views/NoteEditor.vue') },
      { path: 'favorites', name: 'Favorites', component: () => import('@/views/Favorites.vue') },
      { path: 'archived', name: 'Archived', component: () => import('@/views/Archived.vue') },
      { path: 'tags', name: 'Tags', component: () => import('@/views/Tags.vue') },
      { path: 'recycle', name: 'RecycleBin', component: () => import('@/views/RecycleBin.vue') },
      { path: 'shares', name: 'Shares', component: () => import('@/views/Shares.vue') },
      { path: 'templates', name: 'Templates', component: () => import('@/views/Templates.vue') },
      { path: 'teams', name: 'Teams', component: () => import('@/views/Teams.vue') },
      { path: 'teams/:id', name: 'TeamDetail', component: () => import('@/views/Teams.vue') },
      { path: 'settings', name: 'Settings', component: () => import('@/views/Settings.vue') },
      { path: 'login-log', name: 'LoginLog', component: () => import('@/views/LoginLog.vue') },
      { path: 'profile', name: 'Profile', component: () => import('@/views/Profile.vue') },
      {
        path: 'admin',
        children: [
          { path: '', name: 'AdminDashboard', component: () => import('@/views/admin/Dashboard.vue') },
          { path: 'users', name: 'AdminUsers', component: () => import('@/views/admin/Users.vue') },
          { path: 'notes', name: 'AdminAllNotes', component: () => import('@/views/admin/Notes.vue') },
          { path: 'settings', name: 'AdminSettings', component: () => import('@/views/admin/Settings.vue') },
          { path: 'backup', name: 'AdminBackup', component: () => import('@/views/admin/Backup.vue') },
          { path: 'invites', name: 'AdminInvites', component: () => import('@/views/admin/Invites.vue') },
        ]
      }
    ]
  },
  { path: '/:pathMatch(.*)*', name: 'NotFound', component: () => import('@/views/NotFound.vue') }
]

const router = createRouter({
  history: createWebHashHistory(),
  routes
})

router.beforeEach(async (to, from, next) => {
  const userStore = useUserStore()
  if (!userStore.user) await userStore.fetchCurrentUser()
  if (to.meta.requiresAuth && !userStore.isLoggedIn) {
    next({ name: 'Login', query: { redirect: to.fullPath } })
  } else if (to.path.startsWith('/admin') && !userStore.isAdmin) {
    next({ name: 'Notes' })
  } else if (to.meta.guest && userStore.isLoggedIn) {
    next({ name: 'Notes' })
  } else {
    next()
  }
})

export default router