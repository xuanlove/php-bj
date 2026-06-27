<template>
  <div class="page"><h1>笔记模板</h1>
    <div v-if="loading" class="loading"><i class="fas fa-spinner fa-spin"></i></div>
    <div v-else-if="templates.length === 0" class="empty"><i class="fas fa-copy"></i><p>暂无模板</p></div>
    <div v-else class="template-grid">
      <div v-for="t in templates" :key="t.id" class="template-card" @click="useTemplate(t.id)">
        <h3>{{ t.name }}</h3>
        <p>{{ t.description || '' }}</p>
        <small>{{ t.category || '通用' }}</small>
      </div>
    </div>
  </div>
</template>
<script setup>
import { ref, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { templatesApi } from '@/api'

const router = useRouter()
const templates = ref([])
const loading = ref(false)

onMounted(async () => {
  loading.value = true
  const r = await templatesApi.list()
  if (r.success) templates.value = r.templates || []
  loading.value = false
})

async function useTemplate(id) {
  const r = await templatesApi.use(id)
  if (r.success) router.push(`/note/${r.note_id}`)
  else alert(r.message)
}
</script>
<style scoped>
.page { max-width: 1400px; margin: 0 auto; }
.page h1 { font-size: 24px; margin-bottom: 24px; }
.loading, .empty { text-align: center; padding: 60px; color: var(--text-muted); }
.empty i { font-size: 48px; margin-bottom: 16px; }
.template-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 20px; }
.template-card { padding: 20px; background: var(--secondary-bg); border: 1px solid var(--border-color); border-radius: 12px; cursor: pointer; }
.template-card:hover { border-color: var(--accent-gold); }
.template-card h3 { font-size: 16px; margin-bottom: 8px; }
.template-card p { font-size: 13px; color: var(--text-secondary); margin-bottom: 8px; }
.template-card small { font-size: 11px; color: var(--accent-gold); background: var(--hover-bg); padding: 2px 8px; border-radius: 4px; }
</style>