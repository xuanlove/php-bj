<template>
  <div class="page">
    <h1>登录日志</h1>
    <div class="stats-cards">
      <div class="stat-card"><div class="stat-value">{{ stats.total_logins || 0 }}</div><div class="stat-label">总登录次数</div></div>
      <div class="stat-card"><div class="stat-value">{{ stats.failed_attempts_24h || 0 }}</div><div class="stat-label">24h失败</div></div>
      <div class="stat-card"><div class="stat-value">{{ stats.last_login?.created_at ? formatDate(stats.last_login.created_at) : '-' }}</div><div class="stat-label">最后登录</div></div>
    </div>
    <div v-if="loading" class="loading"><i class="fas fa-spinner fa-spin"></i></div>
    <div v-else-if="logs.length === 0" class="empty"><i class="fas fa-sign-in-alt"></i><p>暂无登录记录</p></div>
    <div v-else class="log-list">
      <div v-for="l in logs" :key="l.id" :class="['log-item', l.login_status]">
        <i :class="l.login_status === 'success' ? 'fas fa-check-circle' : 'fas fa-times-circle'"></i>
        <span class="log-status">{{ l.login_status === 'success' ? '成功' : '失败' }}</span>
        <span class="log-ip">{{ l.ip_address }}</span>
        <span class="log-time">{{ formatTime(l.created_at) }}</span>
      </div>
    </div>
  </div>
</template>
<script setup>
import { ref, onMounted } from 'vue'
import { loginLogApi } from '@/api'
import dayjs from 'dayjs'

const logs = ref([])
const stats = ref({})
const loading = ref(false)

onMounted(async () => {
  loading.value = true
  const [r1, r2] = await Promise.all([loginLogApi.history(20, 0), loginLogApi.stats()])
  if (r1.success) logs.value = r1.logs || []
  if (r2.success) stats.value = r2
  loading.value = false
})

function formatDate(d) { return dayjs(d).format('YYYY-MM-DD HH:mm') }
function formatTime(t) { return dayjs(t).fromNow() }
</script>
<style scoped>
.page { max-width: 900px; margin: 0 auto; }
.page h1 { font-size: 24px; margin-bottom: 24px; }
.stats-cards { display: flex; gap: 16px; margin-bottom: 24px; }
.stat-card { flex: 1; padding: 20px; background: var(--secondary-bg); border: 1px solid var(--border-color); border-radius: 12px; text-align: center; }
.stat-value { font-size: 24px; font-weight: 600; color: var(--accent-gold); }
.stat-label { font-size: 13px; color: var(--text-secondary); margin-top: 4px; }
.loading, .empty { text-align: center; padding: 60px; color: var(--text-muted); }
.empty i { font-size: 48px; margin-bottom: 16px; }
.log-list { display: flex; flex-direction: column; gap: 8px; }
.log-item { display: flex; align-items: center; gap: 12px; padding: 12px 16px; background: var(--secondary-bg); border: 1px solid var(--border-color); border-radius: 8px; }
.log-item i { font-size: 20px; }
.log-item.success i { color: var(--success); }
.log-item.failed i { color: var(--danger); }
.log-status { font-weight: 500; min-width: 40px; }
.log-ip { color: var(--text-secondary); font-size: 13px; }
.log-time { margin-left: auto; font-size: 12px; color: var(--text-muted); }
</style>