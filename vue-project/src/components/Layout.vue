<template>
  <div class="layout">
    <aside class="sidebar">
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
          <div class="nav-item" :class="{ active: isActive('/') }" @click="router.push('/')">
            <i class="fas fa-sticky-note"></i><span>所有笔记</span>
          </div>
          <div class="nav-item" :class="{ active: isActive('/favorites') }" @click="router.push('/favorites')">
            <i class="fas fa-star"></i><span>收藏夹</span>
          </div>
          <div class="nav-item" :class="{ active: isActive('/archived') }" @click="router.push('/archived')">
            <i class="fas fa-archive"></i><span>归档</span>
          </div>
          <div class="nav-item" :class="{ active: isActive('/tags') }" @click="router.push('/tags')">
            <i class="fas fa-tags"></i><span>标签</span>
          </div>
        </div>

        <div class="nav-section">
          <div class="nav-section-title">管理</div>
          <div class="nav-item" :class="{ active: isActive('/recycle') }" @click="router.push('/recycle')">
            <i class="fas fa-trash-alt"></i><span>回收站</span>
          </div>
          <div class="nav-item" :class="{ active: isActive('/shares') }" @click="router.push('/shares')">
            <i class="fas fa-share-alt"></i><span>我的分享</span>
          </div>
          <div class="nav-item" :class="{ active: isActive('/templates') }" @click="router.push('/templates')">
            <i class="fas fa-copy"></i><span>模板</span>
          </div>
          <div class="nav-item" :class="{ active: isActive('/teams') }" @click="router.push('/teams')">
            <i class="fas fa-users"></i><span>团队</span>
          </div>
        </div>

        <div class="nav-section">
          <div class="nav-section-title">账户</div>
          <div class="nav-item" :class="{ active: isActive('/settings') }" @click="router.push('/settings')">
            <i class="fas fa-cog"></i><span>设置</span>
          </div>
          <div class="nav-item" :class="{ active: isActive('/login-log') }" @click="router.push('/login-log')">
            <i class="fas fa-sign-in-alt"></i><span>登录日志</span>
          </div>
          <div class="nav-item" :class="{ active: isActive('/profile') }" @click="router.push('/profile')">
            <i class="fas fa-user"></i><span>个人资料</span>
          </div>
        </div>

        <div class="nav-section" v-if="isAdmin">
          <div class="nav-section-title">系统</div>
          <div class="nav-item" :class="{ active: isActive('/admin') }" @click="router.push('/admin')">
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

    <main class="main-content">
      <header class="top-bar">
        <div class="search-box">
          <i class="fas fa-search"></i>
          <input
            type="text"
            v-model="searchQuery"
            placeholder="搜索笔记..."
            @keyup.enter="handleSearch"
          >
        </div>

        <div class="top-bar-actions">
          <button class="icon-btn" @click="notificationsStore.fetchUnreadCount(); showNotifications = !showNotifications">
            <i class="fas fa-bell"></i>
            <span class="badge" v-if="unreadCount > 0">{{ unreadCount }}</span>
          </button>
          <button class="btn btn-primary" @click="createNote">
            <i class="fas fa-plus"></i>新建
          </button>
        </div>
      </header>

      <div class="content-area">
        <slot />
      </div>
    </main>

    <NotificationsPanel v-if="showNotifications" @close="showNotifications = false" />
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
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

const userInitials = computed(() => user.value?.username?.slice(0, 2).toUpperCase() || '?')

onMounted(() => {
  notificationsStore.fetchUnreadCount()
})

function isActive(path) {
  return route.path === path
}

function handleSearch() {
  if (searchQuery.value.trim()) {
    router.push({ query: { search: searchQuery.value } })
  }
}

async function createNote() {
  const r = await notesStore.createNote({ title: '新建笔记', content: '' })
  if (r.success) router.push(`/note/${r.note_id}`)
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
  &.active { background: var(--hover-bg); color: var(--accent-gold); }
}

.sidebar-footer { padding: 16px; border-top: 1px solid var(--border-color); }
.btn-block { width: 100%; }

.main-content { flex: 1; margin-left: 260px; display: flex; flex-direction: column; min-height: 100vh; }

.top-bar { height: 60px; padding: 0 24px; display: flex; align-items: center; justify-content: space-between; background: var(--secondary-bg); border-bottom: 1px solid var(--border-color); position: sticky; top: 0; z-index: 50; }

.search-box { display: flex; align-items: center; gap: 10px; padding: 8px 16px; background: var(--primary-bg); border: 1px solid var(--border-color); border-radius: 8px; width: 400px;
  i { color: var(--text-muted); }
  input { flex: 1; background: none; border: none; outline: none; color: var(--text-primary); &::placeholder { color: var(--text-muted); } }
}

.top-bar-actions { display: flex; align-items: center; gap: 8px; }

.icon-btn { width: 40px; height: 40px; display: flex; align-items: center; justify-content: center; background: var(--hover-bg); border: none; border-radius: 8px; color: var(--text-secondary); cursor: pointer; position: relative;
  &:hover { color: var(--text-primary); background: var(--tertiary-bg); }
  .badge { position: absolute; top: -4px; right: -4px; min-width: 18px; height: 18px; padding: 0 5px; background: var(--danger); border-radius: 9px; font-size: 11px; color: white; display: flex; align-items: center; justify-content: center; }
}

.content-area { flex: 1; padding: 24px; overflow-y: auto; }
</style>