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
    <h2>邀请码管理</h2>
    <div class="toolbar">
      <button class="btn btn-primary" @click="generateCodes"><i class="fas fa-plus"></i>生成5个</button>
    </div>
    <div v-if="loading" class="loading"><i class="fas fa-spinner fa-spin"></i></div>
    <div v-else-if="codes.length === 0" class="empty">暂无邀请码</div>
    <table v-else class="table">
      <thead><tr><th>ID</th><th>代码</th><th>状态</th><th>创建者</th><th>过期</th><th>操作</th></tr></thead>
      <tbody>
        <tr v-for="c in codes" :key="c.id">
          <td>{{ c.id }}</td><td style="font-family:monospace">{{ c.code?.substring(0, 16) }}...</td>
          <td><span class="badge" :class="c.status">{{ c.status }}</span></td>
          <td>{{ c.created_by }}</td><td>{{ c.expires_at || '永久' }}</td>
          <td><button class="btn-sm btn-danger" @click="deleteCode(c.id)">删除</button></td>
        </tr>
      </tbody>
    </table>
  </div>
</template>
<script setup>
import { ref, onMounted } from 'vue'
import { adminApi } from '@/api'

const codes = ref([])
const loading = ref(false)

onMounted(async () => {
  loading.value = true
  const r = await adminApi.inviteList()
  if (r.success) codes.value = r.data?.codes || r.data || []
  loading.value = false
})

async function generateCodes() {
  const r = await adminApi.generateInvite(5, 30)
  if (r.success) {
    alert(`生成了 ${r.count} 个邀请码`)
    const list = await adminApi.inviteList()
    if (list.success) codes.value = list.data?.codes || list.data || []
  }
}

async function deleteCode(id) {
  if (!confirm('确定删除？')) return
  const r = await adminApi.deleteInvite(id)
  if (r.success) codes.value = codes.value.filter(x => x.id !== id)
}
</script>
<style scoped>
.admin-nav { display: flex; gap: 8px; margin-bottom: 24px; flex-wrap: wrap; }
.nav-btn { padding: 10px 20px; background: var(--secondary-bg); border: 1px solid var(--border-color); border-radius: 8px; color: var(--text-secondary); text-decoration: none; font-size: 14px; }
.nav-btn:hover { border-color: var(--accent-gold); }
:deep(.router-link-exact-active) { border-color: var(--accent-gold); color: var(--accent-gold); }
h2 { font-size: 20px; margin-bottom: 20px; }
.toolbar { margin-bottom: 20px; }
.loading, .empty { text-align: center; padding: 40px; color: var(--text-muted); }
.table { width: 100%; border-collapse: collapse; }
.table th, .table td { padding: 12px 16px; text-align: left; border-bottom: 1px solid var(--border-color); }
.table th { color: var(--text-muted); font-size: 12px; text-transform: uppercase; }
.badge { padding: 2px 8px; border-radius: 4px; font-size: 12px; }
.badge.active { background: var(--success); color: white; }
.badge.used { background: var(--hover-bg); color: var(--text-muted); }
.badge.expired { background: var(--danger); color: white; }
.btn-sm { padding: 4px 10px; font-size: 12px; border: none; border-radius: 4px; cursor: pointer; }
</style>