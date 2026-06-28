<template>
  <div class="page">
    <h1>回收站</h1>
    <div v-if="notes.length === 0" class="empty">回收站为空</div>
    <div v-else>
      <button class="btn btn-danger" @click="emptyAll" style="margin-bottom:20px">清空回收站</button>
      <div v-for="note in notes" :key="note.id" class="item">
        <div><h3>{{ note.title }}</h3><p>{{ note.deleted_at }}</p></div>
        <div>
          <button class="btn btn-primary btn-sm" @click="restore(note.id)">恢复</button>
          <button class="btn btn-danger btn-sm" @click="del(note.id)">删除</button>
        </div>
      </div>
    </div>
    <button class="btn btn-ghost" @click="router.push('/')">返回笔记</button>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { recycleApi } from '@/api'

const router = useRouter()
const notes = ref([])

onMounted(async () => {
  const r = await recycleApi.list(50, 0)
  if (r.success) notes.value = r.notes || []
})

async function restore(id) {
  const r = await recycleApi.restore(id)
  if (r.success) {
    notes.value = notes.value.filter(n => n.id !== id)
  } else {
    alert(r.message || '恢复失败')
  }
}

async function del(id) {
  if (confirm('确定永久删除?')) {
    const r = await recycleApi.permanentDelete(id)
    if (r.success) {
      notes.value = notes.value.filter(n => n.id !== id)
    } else {
      alert(r.message || '删除失败')
    }
  }
}

async function emptyAll() {
  if (confirm('清空所有?')) {
    const r = await recycleApi.empty()
    if (r.success) {
      notes.value = []
    } else {
      alert(r.message || '清空失败')
    }
  }
}
</script>

<style scoped>
.page { max-width: 800px; margin: 0 auto; padding: 24px; }
.page h1 { font-size: 24px; margin-bottom: 20px; }
.item { display: flex; justify-content: space-between; align-items: center; padding: 16px; background: var(--secondary-bg); border: 1px solid var(--border-color); border-radius: 8px; margin-bottom: 8px; }
.item h3 { margin-bottom: 4px; }
.item p { font-size: 12px; color: var(--text-muted); }
.btn-sm { padding: 6px 12px; font-size: 12px; margin-left: 8px; }
.empty { text-align: center; padding: 60px; color: var(--text-muted); }
</style>