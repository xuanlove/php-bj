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
    <h2>笔记管理</h2>
    <div v-if="loading" class="loading"><i class="fas fa-spinner fa-spin"></i></div>
    <table v-else class="table">
      <thead><tr><th>ID</th><th>标题</th><th>作者</th><th>类型</th><th>更新</th><th>操作</th></tr></thead>
      <tbody>
        <tr v-for="n in notes" :key="n.id">
          <td>{{ n.id }}</td><td>{{ n.title?.substring(0, 30) || '无标题' }}</td><td>{{ n.username }}</td>
          <td>{{ n.content_type }}</td><td>{{ formatDate(n.updated_at) }}</td>
          <td>
            <button class="btn-sm btn-primary" @click="router.push('/note/'+n.id)">查看</button>
            <button class="btn-sm btn-danger" @click="deleteNote(n.id)">删除</button>
          </td>
        </tr>
      </tbody>
    </table>
  </div>
</template>
<script setup>
import { ref, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { adminApi } from '@/api'
import dayjs from 'dayjs'

const router = useRouter()
const notes = ref([])
const loading = ref(false)

onMounted(async () => {
  loading.value = true
  const r = await adminApi.allNotes()
  if (r.success) notes.value = r.notes || []
  loading.value = false
})

function formatDate(d) { return dayjs(d).format('MM-DD HH:mm') }

async function deleteNote(id) {
  if (!confirm('确定删除此笔记？')) return
  const r = await adminApi.deleteNote(id)
  if (r.success) notes.value = notes.value.filter(x => x.id !== id)
  else alert(r.message)
}
</script>
<style scoped>
.admin-nav { display: flex; gap: 8px; margin-bottom: 24px; flex-wrap: wrap; }
.nav-btn { padding: 10px 20px; background: var(--secondary-bg); border: 1px solid var(--border-color); border-radius: 8px; color: var(--text-secondary); text-decoration: none; font-size: 14px; }
.nav-btn:hover { border-color: var(--accent-gold); }
:deep(.router-link-exact-active) { border-color: var(--accent-gold); color: var(--accent-gold); }
h2 { font-size: 20px; margin-bottom: 20px; }
.loading { text-align: center; padding: 40px; color: var(--text-muted); }
.table { width: 100%; border-collapse: collapse; }
.table th, .table td { padding: 12px 16px; text-align: left; border-bottom: 1px solid var(--border-color); }
.table th { color: var(--text-muted); font-size: 12px; text-transform: uppercase; }
.btn-sm { padding: 4px 10px; font-size: 12px; border: none; border-radius: 4px; cursor: pointer; margin-right: 4px; }
</style>