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
    <h2>备份管理</h2>
    <div v-if="loading" class="loading"><i class="fas fa-spinner fa-spin"></i></div>
    <div v-else>
      <div v-for="cfg in configs" :key="cfg.id" class="backup-item">
        <div class="backup-info">
          <h3>{{ cfg.backup_name }}</h3>
          <p>类型: {{ cfg.storage_type }} | 频率: {{ cfg.backup_frequency }} | 保留: {{ cfg.retention_count }}份</p>
          <small :class="cfg.enabled ? 'text-success' : 'text-muted'">{{ cfg.enabled ? '已启用' : '已禁用' }}</small>
        </div>
        <div class="backup-actions">
          <button class="btn-sm btn-primary" @click="performBackup(cfg.id)">备份</button>
          <button class="btn-sm btn-secondary" @click="deleteConfig(cfg.id)">删除</button>
        </div>
      </div>
      <div v-if="configs.length === 0" class="empty">暂无备份配置</div>
    </div>
  </div>
</template>
<script setup>
import { ref, onMounted } from 'vue'
import { adminApi } from '@/api'

const configs = ref([])
const loading = ref(false)

onMounted(async () => {
  loading.value = true
  const r = await adminApi.backupConfigs()
  if (r.success) configs.value = r.configs || []
  loading.value = false
})

async function performBackup(id) {
  const r = await adminApi.performBackup(id)
  alert(r.success ? '备份已启动' : r.message)
}

async function deleteConfig(id) {
  if (!confirm('确定删除此备份配置？')) return
  const r = await adminApi.deleteBackup(id)
  if (r.success) configs.value = configs.value.filter(x => x.id !== id)
  else alert(r.message)
}
</script>
<style scoped>
.admin-nav { display: flex; gap: 8px; margin-bottom: 24px; flex-wrap: wrap; }
.nav-btn { padding: 10px 20px; background: var(--secondary-bg); border: 1px solid var(--border-color); border-radius: 8px; color: var(--text-secondary); text-decoration: none; font-size: 14px; }
.nav-btn:hover { border-color: var(--accent-gold); }
:deep(.router-link-exact-active) { border-color: var(--accent-gold); color: var(--accent-gold); }
h2 { font-size: 20px; margin-bottom: 20px; }
.loading, .empty { text-align: center; padding: 40px; color: var(--text-muted); }
.backup-item { display: flex; justify-content: space-between; align-items: center; padding: 16px; background: var(--secondary-bg); border: 1px solid var(--border-color); border-radius: 8px; margin-bottom: 12px; }
.backup-info h3 { font-size: 16px; margin-bottom: 4px; }
.backup-info p { font-size: 13px; color: var(--text-secondary); margin-bottom: 4px; }
.btn-sm { padding: 4px 10px; font-size: 12px; border: none; border-radius: 4px; cursor: pointer; margin-left: 4px; }
.text-success { color: var(--success); }
.text-muted { color: var(--text-muted); }
</style>