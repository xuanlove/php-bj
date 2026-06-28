<template>
  <div class="page">
    <!-- 详情视图 -->
    <div v-if="currentTeam" class="team-detail">
      <button class="btn btn-ghost back-btn" @click="router.push('/teams')">
        <i class="fas fa-arrow-left"></i> 返回列表
      </button>
      <h1>{{ currentTeam.name }}</h1>
      <p class="team-desc">{{ currentTeam.description || '暂无描述' }}</p>
      <small class="team-meta">{{ currentTeam.member_count || '?' }} 名成员</small>
    </div>

    <!-- 列表视图 -->
    <template v-else>
      <h1>团队空间</h1>
      <div v-if="loading" class="loading"><i class="fas fa-spinner fa-spin"></i></div>
      <div v-else-if="teams.length === 0" class="empty"><i class="fas fa-users"></i><p>暂无团队，请联系管理员邀请</p></div>
      <div v-else class="teams-grid">
        <div v-for="t in teams" :key="t.id" class="team-card" @click="router.push(`/teams/${t.id}`)">
          <h3>{{ t.name }}</h3>
          <p>{{ t.description || '' }}</p>
          <small>{{ t.member_count || '?' }} 名成员</small>
        </div>
      </div>
    </template>
  </div>
</template>
<script setup>
import { ref, onMounted, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { teamsApi } from '@/api'

const route = useRoute()
const router = useRouter()
const teams = ref([])
const currentTeam = ref(null)
const loading = ref(false)

async function loadTeam(id) {
  loading.value = true
  try {
    const r = await teamsApi.get(id)
    if (r.success) currentTeam.value = r.team || r.data
  } finally { loading.value = false }
}

async function loadList() {
  loading.value = true
  try {
    const r = await teamsApi.list()
    if (r.success) teams.value = r.teams || r.data || []
  } finally { loading.value = false }
}

onMounted(() => {
  if (route.params.id) loadTeam(route.params.id)
  else loadList()
})

watch(() => route.params.id, (id) => {
  if (id) loadTeam(id)
  else { currentTeam.value = null; loadList() }
})
</script>
<style scoped>
.page { max-width: 1400px; margin: 0 auto; }
.page h1 { font-size: 24px; margin-bottom: 24px; }
.loading, .empty { text-align: center; padding: 60px; color: var(--text-muted); }
.empty i { font-size: 48px; margin-bottom: 16px; }
.teams-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 20px; }
.team-card { padding: 20px; background: var(--secondary-bg); border: 1px solid var(--border-color); border-radius: 12px; cursor: pointer; }
.team-card:hover { border-color: var(--accent-gold); }
.team-card h3 { font-size: 16px; margin-bottom: 8px; }
.team-card p { font-size: 13px; color: var(--text-secondary); margin-bottom: 8px; }
.team-card small { font-size: 12px; color: var(--text-muted); }
.team-detail .back-btn { margin-bottom: 16px; }
.team-detail .team-desc { font-size: 14px; color: var(--text-secondary); margin-bottom: 12px; }
.team-detail .team-meta { font-size: 12px; color: var(--text-muted); }
</style>
