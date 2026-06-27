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

    <div class="stats-cards">
      <div class="stat-card"><div class="stat-value">{{ stats.total_users || '...' }}</div><div class="stat-label">用户数</div></div>
      <div class="stat-card"><div class="stat-value">{{ stats.total_notes || '...' }}</div><div class="stat-label">笔记数</div></div>
      <div class="stat-card"><div class="stat-value">{{ stats.total_attachments || '...' }}</div><div class="stat-label">附件数</div></div>
      <div class="stat-card"><div class="stat-value">{{ stats.ai_interactions || '...' }}</div><div class="stat-label">AI调用</div></div>
    </div>
  </div>
</template>
<script setup>
import { ref, onMounted } from 'vue'
import { adminApi } from '@/api'

const stats = ref({})

onMounted(async () => {
  const r = await adminApi.stats()
  if (r.success) stats.value = r.data
})
</script>
<style scoped>
.admin-nav { display: flex; gap: 8px; margin-bottom: 24px; flex-wrap: wrap; }
.nav-btn { padding: 10px 20px; background: var(--secondary-bg); border: 1px solid var(--border-color); border-radius: 8px; color: var(--text-secondary); text-decoration: none; font-size: 14px; }
.nav-btn:hover { border-color: var(--accent-gold); }
:deep(.router-link-exact-active) { border-color: var(--accent-gold); color: var(--accent-gold); }
.stats-cards { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 16px; }
.stat-card { padding: 24px; background: var(--secondary-bg); border: 1px solid var(--border-color); border-radius: 12px; text-align: center; }
.stat-value { font-size: 36px; font-weight: 700; color: var(--accent-gold); }
.stat-label { font-size: 14px; color: var(--text-secondary); margin-top: 8px; }
</style>