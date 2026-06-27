<template>
  <div class="page"><h1>收藏夹</h1><div v-if="notes.length === 0" class="empty">还没有收藏任何笔记</div>
    <div v-else class="notes-grid">
      <div v-for="n in notes" :key="n.id" class="note-card" @click="router.push('/note/'+n.id)">
        <h3>{{ n.title || '无标题' }}</h3><p>{{ n.content?.substring(0,80) }}</p>
        <div class="note-meta">{{ formatDate(n.updated_at) }}</div>
      </div>
    </div>
  </div>
</template>
<script setup>
import { ref, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { useNotesStore } from '@/stores/notes'
import { storeToRefs } from 'pinia'
import dayjs from 'dayjs'
const router = useRouter()
const notesStore = useNotesStore()
const { notes } = storeToRefs(notesStore)
onMounted(() => notesStore.fetchNotes({ is_favorite: 1 }))
function formatDate(d) { return dayjs(d).fromNow() }
</script>
<style scoped>
.page { max-width: 1400px; margin: 0 auto; }
.page h1 { font-size: 24px; margin-bottom: 24px; }
.empty { text-align: center; padding: 60px; color: var(--text-muted); }
.notes-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 20px; }
.note-card { background: var(--secondary-bg); border: 1px solid var(--border-color); border-radius: 12px; padding: 16px; cursor: pointer; }
.note-card:hover { border-color: var(--accent-gold); }
.note-card h3 { font-size: 16px; margin-bottom: 8px; }
.note-card p { font-size: 13px; color: var(--text-secondary); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.note-meta { font-size: 12px; color: var(--text-muted); margin-top: 8px; }
</style>