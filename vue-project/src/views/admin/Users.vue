<template>
  <div>
    <div class="admin-nav">
      <router-link to="/admin" class="nav-btn">仪表板</router-link>
      <router-link to="/admin/users" class="nav-btn">用户管理</router-link>
      <router-link to="/admin/notes" class="nav-btn">笔记管理</router-link>
      <router-link to="/admin/settings" class="nav-btn">系统设置</router-link>
      <router-link to="/admin/backup" class="nav-btn">备份管理</router-link>
      <router-link to="/admin/invites" class="nav-btn">邀请码</router-link>
      <router-link to="/" class="nav-btn">返回前台</router-link>
    </div>
    <h2>用户管理</h2>
    <div v-if="loading" class="loading"><i class="fas fa-spinner fa-spin"></i></div>
    <table v-else class="table">
      <thead><tr><th>ID</th><th>用户名</th><th>邮箱</th><th>角色</th><th>状态</th><th>注册时间</th><th>操作</th></tr></thead>
      <tbody>
        <tr v-for="u in users" :key="u.id">
          <td>{{ u.id }}</td><td>{{ u.username }}</td><td>{{ u.email }}</td>
          <td><span class="badge" :class="u.role">{{ u.role }}</span></td>
          <td>{{ u.status }}</td>
          <td>{{ formatDate(u.created_at) }}</td>
          <td>
            <button class="btn-sm" :class="u.status === 'suspended' || u.status === 'disabled' ? 'btn-primary' : 'btn-secondary'" @click="toggleStatus(u)">{{ u.status === 'active' ? '封禁' : '解封' }}</button>
            <button class="btn-sm btn-danger" @click="deleteUser(u.id)">删除</button>
          </td>
        </tr>
      </tbody>
    </table>
  </div>
</template>
<script setup>
import { ref, onMounted } from 'vue'
import { adminApi } from '@/api'
import dayjs from 'dayjs'

const users = ref([])
const loading = ref(false)

onMounted(async () => {
  loading.value = true
  const r = await adminApi.allUsers()
  if (r.success) users.value = r.users || []
  loading.value = false
})

function formatDate(d) { return dayjs(d).format('YYYY-MM-DD') }

async function toggleStatus(u) {
  // "解封"语义:任何非 active 状态都回到 active;"封禁":active → suspended
  const newStatus = u.status === 'active' ? 'suspended' : 'active'
  const r = await adminApi.toggleUserStatus(u.id, newStatus)
  if (r.success) u.status = newStatus
  else alert(r.message)
}

async function deleteUser(id) {
  if (!confirm('确定删除该用户？')) return
  const r = await adminApi.deleteUser(id)
  if (r.success) users.value = users.value.filter(x => x.id !== id)
  else alert(r.message)
}
</script>
<style scoped>
.admin-nav { display: flex; gap: 8px; margin-bottom: 24px; flex-wrap: wrap; }
.nav-btn { padding: 10px 20px; background: var(--secondary-bg); border: 1px solid var(--border-color); border-radius: 8px; color: var(--text-secondary); text-decoration: none; font-size: 14px; }
.nav-btn:hover { border-color: var(--accent-gold); }
:deep(.router-link-exact-active) { border-color: var(--accent-gold); color: var(--accent-gold); }
h2 { font-size: 20px; margin-bottom: 20px; }
.loading { text-align: center; padding: 40px; color: var(--text-muted); }
.table { width: 100%; border-collapse: collapse; }
.table th, .table td { padding: 12px 16px; text-align: left; border-bottom: 1px solid var(--border-color); }
.table th { color: var(--text-muted); font-size: 12px; text-transform: uppercase; }
.badge { padding: 2px 8px; border-radius: 4px; font-size: 12px; }
.badge.admin { background: var(--accent-gold); color: #000; }
.badge.user { background: var(--hover-bg); color: var(--text-secondary); }
.btn-sm { padding: 4px 10px; font-size: 12px; border: none; border-radius: 4px; cursor: pointer; margin-right: 4px; }
</style>