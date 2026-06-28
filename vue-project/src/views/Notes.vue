<template>
  <div class="notes-page">
    <div class="page-header">
      <h1>{{ pageTitle }}</h1>
    </div>

    <div v-if="loading" class="loading"><i class="fas fa-spinner fa-spin"></i> 加载中...</div>
    <div v-else-if="notes.length === 0" class="empty">
      <i class="fas fa-sticky-note"></i>
      <p>暂无笔记</p>
      <button class="btn btn-primary" @click="createNote">创建第一篇笔记</button>
    </div>
    <div v-else class="notes-grid">
      <div v-for="note in notes" :key="note.id" class="note-card" @click="openNote(note.id)">
        <h3>{{ note.title || '无标题' }}</h3>
        <p>{{ note.content?.substring(0, 100) || '' }}</p>
        <div class="note-meta">{{ formatDate(note.updated_at) }}</div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, watch } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { storeToRefs } from 'pinia'
import { useNotesStore } from '@/stores/notes'
import dayjs from 'dayjs'
import 'dayjs/locale/zh-cn'

const router = useRouter()
const route = useRoute()
const notesStore = useNotesStore()
const { notes, loading } = storeToRefs(notesStore)

const pageTitle = computed(() => {
  if (route.query.search) return `搜索: ${route.query.search}`
  return '所有笔记'
})

onMounted(async () => {
  await loadNotes()
})

watch(() => [route.name, route.query.search], async () => { await loadNotes() })

async function loadNotes() {
  // 搜索优先级最高,避免在收藏/归档页搜索时被过滤条件覆盖
  if (route.query.search) {
    await notesStore.searchNotes(route.query.search)
    return
  }
  const filters = {}
  if (route.name === 'Favorites') filters.is_favorite = 1
  else if (route.name === 'Archived') filters.is_archived = 1
  await notesStore.fetchNotes(filters)
}

function formatDate(d) { return dayjs(d).fromNow() }

async function createNote() {
  const r = await notesStore.createNote({ title: '新建笔记', content: '' })
  if (r.success) router.push(`/note/${r.note_id}`)
  else alert(r.message || '创建失败')
}

function openNote(id) { router.push(`/note/${id}`) }
</script>

<style scoped>
.notes-page { max-width: 1400px; margin: 0 auto; }
.page-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 24px; }
.page-header h1 { font-size: 24px; font-weight: 600; }
.loading, .empty { text-align: center; padding: 60px; color: var(--text-muted); }
.loading i, .empty i { font-size: 48px; margin-bottom: 16px; }
.notes-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 20px; }
.note-card { background: var(--secondary-bg); border: 1px solid var(--border-color); border-radius: 12px; padding: 16px; cursor: pointer; transition: all 0.2s; }
.note-card:hover { border-color: var(--accent-gold); transform: translateY(-2px); }
.note-card h3 { font-size: 16px; margin-bottom: 8px; }
.note-card p { font-size: 13px; color: var(--text-secondary); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.note-meta { font-size: 12px; color: var(--text-muted); margin-top: 12px; }
</style>