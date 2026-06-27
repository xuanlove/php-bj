<template>
  <div class="page">
    <h1>我的分享</h1>

    <div class="section">
      <h2>公开分享链接</h2>
      <div v-if="loading" class="loading"><i class="fas fa-spinner fa-spin"></i></div>
      <div v-else-if="publicShares.length === 0" class="empty">暂无分享链接</div>
      <div v-else class="shares-list">
        <div v-for="s in publicShares" :key="s.id" class="share-item">
          <div class="share-info">
            <h3>{{ s.note_title || '笔记' }}</h3>
            <p>{{ s.share_token?.substring(0, 12) }}...</p>
            <small v-if="s.expires_at">有效期至: {{ formatDate(s.expires_at) }}</small>
            <small>访问量: {{ s.view_count || 0 }}</small>
          </div>
          <button class="btn btn-danger btn-sm" @click="deletePublicShare(s.id)">删除</button>
        </div>
      </div>
    </div>

    <div class="section">
      <h2>协作共享</h2>
      <div class="share-tabs">
        <button :class="['tab-btn', { active: shareTab === 'sent' }]" @click="shareTab = 'sent'">我分享的</button>
        <button :class="['tab-btn', { active: shareTab === 'received' }]" @click="shareTab = 'received'">分享给我的</button>
      </div>
      <div v-if="shareTab === 'sent'" class="tab-content">
        <div v-if="sentShares.length === 0" class="empty">暂无协作</div>
        <div v-for="s in sentShares" :key="s.id" class="share-item">
          <div class="share-info">
            <h3>{{ s.note_title || '笔记 #' + s.note_id }}</h3>
            <small>共享给: {{ s.shared_with_username || '用户' }}</small>
            <small>权限: {{ s.permission === 'edit' ? '可编辑' : '只读' }}</small>
          </div>
          <button class="btn btn-danger btn-sm" @click="revokeShare(s.id)">撤销</button>
        </div>
      </div>
      <div v-if="shareTab === 'received'" class="tab-content">
        <div v-if="receivedShares.length === 0" class="empty">暂无协作</div>
        <div v-for="s in receivedShares" :key="s.id" class="share-item">
          <div class="share-info">
            <h3>{{ s.note_title || '笔记 #' + s.note_id }}</h3>
            <small>来自: {{ s.owner_username || '用户' }}</small>
            <small>权限: {{ s.permission === 'edit' ? '可编辑' : '只读' }}</small>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>
<script setup>
import { ref, onMounted } from 'vue'
import { shareApi, noteSharesApi } from '@/api'
import dayjs from 'dayjs'

const publicShares = ref([])
const sentShares = ref([])
const receivedShares = ref([])
const loading = ref(false)
const shareTab = ref('sent')

onMounted(async () => {
  loading.value = true
  const [publicR, sentR, receivedR] = await Promise.all([
    shareApi.list(null),
    noteSharesApi.getSent(),
    noteSharesApi.getReceived()
  ])
  if (publicR.success) publicShares.value = publicR.shares || []
  if (sentR.success) sentShares.value = sentR.shares || []
  if (receivedR.success) receivedShares.value = receivedR.shares || []
  loading.value = false
})

function formatDate(d) { return dayjs(d).format('YYYY-MM-DD') }

async function deletePublicShare(id) {
  if (confirm('确定删除此分享？')) {
    const r = await shareApi.delete(id)
    if (r.success) publicShares.value = publicShares.value.filter(x => x.id !== id)
  }
}

async function revokeShare(id) {
  if (confirm('确定撤销此协作？')) {
    const r = await noteSharesApi.revoke(id)
    if (r.success) sentShares.value = sentShares.value.filter(x => x.id !== id)
  }
}
</script>
<style scoped>
.page { max-width: 900px; margin: 0 auto; }
.page h1 { font-size: 24px; margin-bottom: 24px; }
.section { background: var(--secondary-bg); border: 1px solid var(--border-color); border-radius: 12px; padding: 20px; margin-bottom: 20px; }
.section h2 { font-size: 16px; margin-bottom: 16px; }
.loading, .empty { text-align: center; padding: 40px; color: var(--text-muted); font-size: 14px; }
.share-tabs { display: flex; gap: 4px; margin-bottom: 16px; }
.tab-btn { padding: 8px 16px; background: var(--hover-bg); border: 1px solid var(--border-color); border-radius: 8px; color: var(--text-secondary); cursor: pointer; font-size: 14px; }
.tab-btn.active { border-color: var(--accent-gold); color: var(--accent-gold); }
.tab-content { min-height: 100px; }
.shares-list { display: flex; flex-direction: column; gap: 12px; }
.share-item { display: flex; justify-content: space-between; align-items: center; padding: 16px; background: var(--primary-bg); border-radius: 8px; margin-bottom: 8px; }
.share-info h3 { margin-bottom: 4px; font-size: 15px; }
.share-info p { font-size: 13px; color: var(--accent-gold); margin-bottom: 4px; }
.share-info small { font-size: 12px; color: var(--text-muted); margin-right: 12px; }
.btn-sm { padding: 6px 12px; font-size: 12px; border-radius: 6px; }
.btn-danger { background: rgba(239,68,68,0.15); border: 1px solid rgba(239,68,68,0.3); color: #ef4444; cursor: pointer; }
.btn-danger:hover { background: rgba(239,68,68,0.25); }
</style>