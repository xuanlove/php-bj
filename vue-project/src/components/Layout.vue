<template>
  <div class="layout">
    <aside class="sidebar" :class="{ open: sidebarOpen }">
      <div class="sidebar-header" @click="router.push('/')">
        <h2>PHP笔记</h2>
      </div>

      <div class="sidebar-user" @click="router.push('/profile')">
        <div class="avatar">{{ userInitials }}</div>
        <div class="user-info">
          <span class="username">{{ user?.username }}</span>
          <span class="role">{{ isAdmin ? '管理员' : '用户' }}</span>
        </div>
      </div>

      <nav class="sidebar-nav">
        <div class="nav-section">
          <div class="nav-item" :class="{ active: isActive('/') }" tabindex="0" role="button" @click="router.push('/')" @keydown.enter="router.push('/')">
            <i class="fas fa-sticky-note"></i><span>所有笔记</span>
          </div>
          <div class="nav-item" :class="{ active: isActive('/favorites') }" tabindex="0" role="button" @click="router.push('/favorites')" @keydown.enter="router.push('/favorites')">
            <i class="fas fa-star"></i><span>收藏夹</span>
          </div>
          <div class="nav-item" :class="{ active: isActive('/archived') }" tabindex="0" role="button" @click="router.push('/archived')" @keydown.enter="router.push('/archived')">
            <i class="fas fa-archive"></i><span>归档</span>
          </div>
          <div class="nav-item" :class="{ active: isActive('/tags') }" tabindex="0" role="button" @click="router.push('/tags')" @keydown.enter="router.push('/tags')">
            <i class="fas fa-tags"></i><span>标签</span>
          </div>
        </div>

        <div class="nav-section">
          <div class="nav-section-title">管理</div>
          <div class="nav-item" :class="{ active: isActive('/recycle') }" tabindex="0" role="button" @click="router.push('/recycle')" @keydown.enter="router.push('/recycle')">
            <i class="fas fa-trash-alt"></i><span>回收站</span>
          </div>
          <div class="nav-item" :class="{ active: isActive('/shares') }" tabindex="0" role="button" @click="router.push('/shares')" @keydown.enter="router.push('/shares')">
            <i class="fas fa-share-alt"></i><span>我的分享</span>
          </div>
          <div class="nav-item" :class="{ active: isActive('/templates') }" tabindex="0" role="button" @click="router.push('/templates')" @keydown.enter="router.push('/templates')">
            <i class="fas fa-copy"></i><span>模板</span>
          </div>
          <div class="nav-item" :class="{ active: isActive('/teams') }" tabindex="0" role="button" @click="router.push('/teams')" @keydown.enter="router.push('/teams')">
            <i class="fas fa-users"></i><span>团队</span>
          </div>
        </div>

        <div class="nav-section">
          <div class="nav-section-title">账户</div>
          <div class="nav-item" :class="{ active: isActive('/settings') }" tabindex="0" role="button" @click="router.push('/settings')" @keydown.enter="router.push('/settings')">
            <i class="fas fa-cog"></i><span>设置</span>
          </div>
          <div class="nav-item" :class="{ active: isActive('/login-log') }" tabindex="0" role="button" @click="router.push('/login-log')" @keydown.enter="router.push('/login-log')">
            <i class="fas fa-sign-in-alt"></i><span>登录日志</span>
          </div>
          <div class="nav-item" :class="{ active: isActive('/profile') }" tabindex="0" role="button" @click="router.push('/profile')" @keydown.enter="router.push('/profile')">
            <i class="fas fa-user"></i><span>个人资料</span>
          </div>
        </div>

        <div class="nav-section" v-if="isAdmin">
          <div class="nav-section-title">系统</div>
          <div class="nav-item" :class="{ active: isActive('/admin') }" tabindex="0" role="button" @click="router.push('/admin')" @keydown.enter="router.push('/admin')">
            <i class="fas fa-cogs"></i><span>管理后台</span>
          </div>
        </div>
      </nav>

      <div class="sidebar-footer">
        <button class="btn btn-ghost btn-block" @click="handleLogout">
          <i class="fas fa-sign-out-alt"></i>退出登录
        </button>
      </div>
    </aside>

    <div class="sidebar-overlay" :class="{ show: sidebarOpen }" @click="sidebarOpen = false"></div>

    <main class="main-content">
      <header class="top-bar">
        <button class="mobile-menu-toggle" @click="sidebarOpen = !sidebarOpen">
          <i class="fas fa-bars"></i>
        </button>
        <div class="search-box">
          <i class="fas fa-search"></i>
          <input
            type="text"
            v-model="searchQuery"
            placeholder="搜索笔记..."
            aria-label="搜索笔记"
            @keyup.enter="handleSearch"
          >
        </div>

        <div class="top-bar-actions">
          <button class="icon-btn" aria-label="通知" @click="notificationsStore.fetchUnreadCount(); showNotifications = !showNotifications">
            <i class="fas fa-bell"></i>
            <span class="badge" v-if="unreadCount > 0">{{ unreadCount > 99 ? '99+' : unreadCount }}</span>
          </button>
          <button class="btn btn-primary" :disabled="creating" @click="createNote">
            <i class="fas fa-plus"></i>新建
          </button>
        </div>
      </header>

      <div class="content-area">
        <router-view />
      </div>
    </main>

    <NotificationsPanel v-if="showNotifications" @close="showNotifications = false" />
  </div>
</template>

<script setup>
import { ref, computed, onMounted, watch } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { storeToRefs } from 'pinia'
import { useUserStore } from '@/stores/user'
import { useNotesStore } from '@/stores/notes'
import { useNotificationsStore } from '@/stores/notifications'
import NotificationsPanel from '@/components/NotificationsPanel.vue'

const router = useRouter()
const route = useRoute()
const userStore = useUserStore()
const notesStore = useNotesStore()
const notificationsStore = useNotificationsStore()

const { user, isAdmin } = storeToRefs(userStore)
const { unreadCount } = storeToRefs(notificationsStore)

const searchQuery = ref('')
const showNotifications = ref(false)
const sidebarOpen = ref(false)
const creating = ref(false)

const userInitials = computed(() => user.value?.username?.slice(0, 2).toUpperCase() || '?')

onMounted(() => {
  notificationsStore.fetchUnreadCount()
})

// 路由变化时关闭移动端侧边栏
watch(() => route.path, () => {
  sidebarOpen.value = false
})

function isActive(path) {
  return route.path === path
}

function handleSearch() {
  if (searchQuery.value.trim()) {
    router.push({ path: '/', query: { search: searchQuery.value } })
  }
}

async function createNote() {
  creating.value = true
  try {
    const r = await notesStore.createNote({ title: '新建笔记', content: '' })
    if (r.success) router.push(`/note/${r.note_id}`)
  } finally {
    creating.value = false
  }
}

async function handleLogout() {
  await userStore.logout()
  router.push('/login')
}
</script>

<style scoped lang="scss">
.layout { display: flex; min-height: 100vh; }

.sidebar {
  width: 260px; background: var(--secondary-bg); border-right: 1px solid var(--border-color);
  position: fixed; top: 0; left: 0; bottom: 0; display: flex; flex-direction: column; z-index: 100;
}

.sidebar-header { padding: 20px; border-bottom: 1px solid var(--border-color); cursor: pointer;
  h2 { font-size: 18px; background: linear-gradient(135deg, var(--accent-gold), var(--accent-copper)); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text; }
}

.sidebar-user { padding: 16px 20px; display: flex; gap: 12px; align-items: center; cursor: pointer; transition: background 0.2s;
  &:hover { background: var(--hover-bg); }
  .avatar { width: 40px; height: 40px; border-radius: 10px; background: linear-gradient(135deg, var(--accent-gold), var(--accent-copper)); display: flex; align-items: center; justify-content: center; font-weight: 600; color: var(--primary-bg); }
  .user-info { flex: 1; overflow: hidden;
    .username { display: block; font-weight: 500; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .role { font-size: 12px; color: var(--text-secondary); }
  }
}

.sidebar-nav { flex: 1; overflow-y: auto; padding: 10px 0; }
.nav-section { padding: 8px 12px; }
.nav-section-title { font-size: 11px; text-transform: uppercase; color: var(--text-muted); padding: 8px 12px; letter-spacing: 0.5px; }
.nav-item { display: flex; align-items: center; gap: 12px; padding: 10px 12px; border-radius: 8px; cursor: pointer; color: var(--text-secondary); transition: all 0.2s;
  i { width: 20px; text-align: center; }
  &:hover { background: var(--hover-bg); color: var(--text-primary); }
  &:focus { outline: none; background: var(--hover-bg); color: var(--text-primary); box-shadow: 0 0 0 2px var(--accent-gold); }
  &.active { background: var(--hover-bg); color: var(--accent-gold); }
}

.sidebar-footer { padding: 16px; border-top: 1px solid var(--border-color); }
.btn-block { width: 100%; }

.main-content { flex: 1; margin-left: 260px; display: flex; flex-direction: column; min-height: 100vh; }

.top-bar { height: 60px; padding: 0 24px; display: flex; align-items: center; justify-content: space-between; background: var(--secondary-bg); border-bottom: 1px solid var(--border-color); position: sticky; top: 0; z-index: 50; }

.search-box { display: flex; align-items: center; gap: 10px; padding: 8px 16px; background: var(--primary-bg); border: 1px solid var(--border-color); border-radius: 8px; max-width: 400px; width: 100%;
  i { color: var(--text-muted); }
  input { flex: 1; background: none; border: none; outline: none; color: var(--text-primary); &::placeholder { color: var(--text-muted); } }
}

.top-bar-actions { display: flex; align-items: center; gap: 8px; }

.icon-btn { width: 40px; height: 40px; display: flex; align-items: center; justify-content: center; background: var(--hover-bg); border: none; border-radius: 8px; color: var(--text-secondary); cursor: pointer; position: relative;
  &:hover { color: var(--text-primary); background: var(--tertiary-bg); }
  .badge { position: absolute; top: -4px; right: -4px; min-width: 18px; height: 18px; padding: 0 5px; background: var(--danger); border-radius: 9px; font-size: 11px; color: white; display: flex; align-items: center; justify-content: center; }
}

.content-area { flex: 1; padding: 24px; overflow-y: auto; }

// 移动端菜单切换按钮
.mobile-menu-toggle {
  display: none;
  background: none;
  border: none;
  color: var(--text-primary, #fff);
  font-size: 20px;
  cursor: pointer;
  padding: 8px;
}

// 侧边栏遮罩
.sidebar-overlay {
  display: none;
  position: fixed;
  inset: 0;
  background: rgba(0, 0, 0, 0.5);
  z-index: 1000;
}

.sidebar-overlay.show {
  display: block;
}

// 移动端响应式适配
@media (max-width: 768px) {
  .sidebar {
    transform: translateX(-100%);
    transition: transform 0.3s ease;
    z-index: 1001;
  }
  .sidebar.open {
    transform: translateX(0);
  }
  .main-content {
    margin-left: 0;
  }
  .top-bar .search-box {
    max-width: 200px;
  }
  .mobile-menu-toggle {
    display: flex !important;
  }
  .sidebar-overlay {
    display: block;
  }
}
</style>