<template>
  <div class="page">
    <h1>标签管理</h1>
    <div class="tags-grid">
      <div v-for="tag in tags" :key="tag.id" class="tag-card" @click="router.push({ path: '/', query: { search: '#' + tag.name } })">
        <i class="fas fa-tag"></i>
        <span class="tag-name">{{ tag.name }}</span>
        <small>{{ tag.note_count || 0 }} 篇</small>
      </div>
    </div>
  </div>
</template>
<script setup>
import { ref, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { tagsApi } from '@/api'

const router = useRouter()
const tags = ref([])

onMounted(async () => {
  const r = await tagsApi.list()
  if (r.success) tags.value = r.tags || []
})
</script>
<style scoped>
.page { max-width: 1400px; margin: 0 auto; }
.page h1 { font-size: 24px; margin-bottom: 24px; }
.tags-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 16px; }
.tag-card { padding: 20px; background: var(--secondary-bg); border: 1px solid var(--border-color); border-radius: 8px; cursor: pointer; text-align: center; transition: all 0.2s; }
.tag-card:hover { border-color: var(--accent-gold); transform: translateY(-2px); }
.tag-card i { font-size: 24px; color: var(--accent-gold); margin-bottom: 8px; }
.tag-name { display: block; font-weight: 500; margin-bottom: 4px; }
.tag-card small { font-size: 12px; color: var(--text-muted); }
</style>