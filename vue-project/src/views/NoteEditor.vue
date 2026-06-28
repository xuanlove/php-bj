<template>
  <div class="editor-page">
    <div class="editor-header">
      <button class="btn btn-ghost" @click="router.push('/')"><i class="fas fa-arrow-left"></i>返回</button>
      <div class="editor-actions">
        <button class="btn btn-ghost" @click="showVersionPanel = !showVersionPanel" title="版本历史"><i class="fas fa-history"></i></button>
        <button class="btn btn-ghost" @click="showShareModal = true" title="分享"><i class="fas fa-share-alt"></i></button>
        <button class="btn btn-ghost" @click="showAttachments = !showAttachments" title="附件"><i class="fas fa-paperclip"></i></button>
        <button class="btn btn-ghost" @click="showComments = !showComments" title="评论"><i class="fas fa-comment"></i></button>
        <button class="btn btn-ghost" @click="showAIPanel = !showAIPanel"><i class="fas fa-robot"></i>AI</button>
        <button class="btn btn-ghost" @click="showMoreMenu = !showMoreMenu"><i class="fas fa-ellipsis-h"></i></button>
        <button class="btn btn-primary" @click="saveNote" :disabled="saving">{{ saving ? '保存中...' : '保存' }}</button>
      </div>
    </div>
    <div class="editor-container">
      <div class="editor-main">
        <input v-model="title" class="title-input" placeholder="笔记标题" @input="autoSave">
        <textarea v-model="content" class="content-textarea" placeholder="开始编辑笔记..." @input="autoSave"></textarea>
      </div>
      <aside class="editor-sidebar" v-if="showAIPanel">
        <div class="ai-panel">
          <h3>AI 助手</h3>
          <div class="provider-select">
            <select v-model="aiProvider" class="input">
              <option value="chatgpt">ChatGPT</option>
              <option value="doubao">豆包</option>
              <option value="claude">Claude</option>
              <option value="custom_openai">自定义</option>
            </select>
          </div>
          <div class="ai-actions">
            <button class="ai-btn" @click="aiAction('proofread')">校对</button>
            <button class="ai-btn" @click="aiAction('continue')">续写</button>
            <button class="ai-btn" @click="aiAction('summarize')">摘要</button>
            <button class="ai-btn" @click="aiAction('rewrite')">重写</button>
          </div>
          <div v-if="aiActionType === 'rewrite'" class="style-select">
            <label>风格:</label>
            <select v-model="aiStyle" class="input">
              <option value="professional">专业</option>
              <option value="casual">休闲</option>
              <option value="academic">学术</option>
              <option value="creative">创意</option>
            </select>
          </div>
          <div v-if="aiResult" class="ai-result">{{ aiResult }}</div>
          <div v-if="aiLoading" class="ai-loading">AI处理中...</div>
        </div>
      </aside>
    </div>

    <!-- 分享弹窗 -->
    <div v-if="showShareModal" class="modal-overlay" @click.self="showShareModal = false">
      <div class="modal">
        <h3>分享笔记</h3>
        <div class="form-group">
          <label>设置密码 (可选)</label>
          <input type="password" v-model="sharePassword" class="input" placeholder="留空无需密码">
        </div>
        <div class="form-group">
          <label>过期时间</label>
          <select v-model="shareExpires" class="input">
            <option :value="0">永不过期</option>
            <option :value="86400">24小时</option>
            <option :value="604800">7天</option>
            <option :value="2592000">30天</option>
          </select>
        </div>
        <div class="form-group">
          <label><input type="checkbox" v-model="shareAllowDownload"> 允许下载</label>
        </div>
        <div class="modal-actions">
          <button class="btn btn-ghost" @click="showShareModal = false">取消</button>
          <button class="btn btn-primary" @click="createShare" :disabled="sharing">{{ sharing ? '创建中...' : '创建分享' }}</button>
        </div>
        <div v-if="shareUrl" class="share-result">
          <p>分享链接:</p>
          <input :value="shareUrl" readonly class="input" @click="$event.target.select()">
        </div>
      </div>
    </div>

    <!-- 更多菜单 -->
    <div v-if="showMoreMenu" class="dropdown-menu">
      <div class="dropdown-item" @click="exportNote('markdown')">导出 Markdown</div>
      <div class="dropdown-item" @click="exportNote('html')">导出 HTML</div>
      <div class="dropdown-item" @click="exportNote('json')">导出 JSON</div>
      <div class="dropdown-divider"></div>
      <div class="dropdown-item danger" @click="deleteNote">删除笔记</div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted, onUnmounted, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useNotesStore } from '@/stores/notes'
import { aiApi, shareApi } from '@/api'

const route = useRoute()
const router = useRouter()
const notesStore = useNotesStore()
const noteId = ref(route.params.id)
const title = ref('')
const content = ref('')
const saving = ref(false)
const showAIPanel = ref(false)
const showVersionPanel = ref(false)
const showAttachments = ref(false)
const showComments = ref(false)
const showShareModal = ref(false)
const showMoreMenu = ref(false)
const aiResult = ref('')
const aiLoading = ref(false)
const aiProvider = ref('chatgpt')
const aiStyle = ref('professional')
const aiActionType = ref('')
const sharePassword = ref('')
const shareExpires = ref(0)
const shareAllowDownload = ref(true)
const shareUrl = ref('')
const sharing = ref(false)
let autoTimer = null

async function loadNote() {
  if (noteId.value !== 'new') {
    const r = await notesStore.fetchNote(noteId.value)
    if (r.success && r.note) {
      title.value = r.note.title || ''
      content.value = r.note.content || ''
    }
  }
}

onMounted(async () => {
  await loadNote()
  try {
    const cfg = await aiApi.config()
    if (cfg.success && cfg.default_provider) aiProvider.value = cfg.default_provider
  } catch (e) { /* use default */ }
})

watch(() => route.params.id, (newId) => {
  if (newId) {
    noteId.value = newId
    loadNote()
  }
})

onUnmounted(() => {
  if (autoTimer) { clearTimeout(autoTimer); autoTimer = null }
})

async function saveNote() {
  if (saving.value) return
  saving.value = true
  try {
    let r
    if (noteId.value === 'new') {
      r = await notesStore.createNote({ title: title.value || '无标题', content: content.value })
      if (r.success) { noteId.value = r.note_id; router.replace(`/note/${r.note_id}`) }
    } else {
      r = await notesStore.updateNote(noteId.value, { title: title.value, content: content.value })
    }
    if (!r.success) alert(r.message)
  } finally { saving.value = false }
}

function autoSave() {
  if (autoTimer) clearTimeout(autoTimer)
  autoTimer = setTimeout(saveNote, 3000)
}

async function aiAction(type) {
  if (!noteId.value || noteId.value === 'new') { alert('请先保存笔记'); return }
  aiActionType.value = type
  aiLoading.value = true; aiResult.value = ''
  try {
    const style = type === 'rewrite' ? aiStyle.value : undefined
    const r = await aiApi[type](noteId.value, aiProvider.value, style)
    if (r.success) aiResult.value = r.content || r.message
    else alert(r.message)
  } finally { aiLoading.value = false }
}

async function createShare() {
  if (noteId.value === 'new') { alert('请先保存笔记'); return }
  sharing.value = true
  try {
    const r = await shareApi.create(noteId.value, {
      password: sharePassword.value || null,
      expires_in: shareExpires.value,
      allow_download: shareAllowDownload.value
    })
    if (r.success) {
      shareUrl.value = `${window.location.origin}/#/share/${r.share_token}`
    } else {
      alert(r.message)
    }
  } finally { sharing.value = false }
}

async function exportNote(format) {
  const r = await notesStore.exportNote(noteId.value, format)
  if (!r.success) {
    alert(r.message || '导出失败')
    showMoreMenu.value = false
    return
  }
  const blob = new Blob([r.content || ''], { type: r.mime_type || 'application/octet-stream' })
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = r.filename || `note.${format}`
  a.click()
  URL.revokeObjectURL(url)
  showMoreMenu.value = false
}

async function deleteNote() {
  if (confirm('确定要删除这篇笔记吗？')) {
    const r = await notesStore.deleteNote(noteId.value)
    if (r.success) router.push('/')
    else alert(r.message)
  }
  showMoreMenu.value = false
}
</script>

<style scoped>
.editor-page { display: flex; flex-direction: column; min-height: 100vh; position: relative; }
.editor-header { display: flex; justify-content: space-between; padding: 12px 24px; background: var(--secondary-bg); border-bottom: 1px solid var(--border-color); }
.editor-actions { display: flex; gap: 8px; align-items: center; }
.editor-container { flex: 1; display: flex; }
.editor-main { flex: 1; padding: 24px; display: flex; flex-direction: column; }
.title-input { width: 100%; padding: 12px 0; margin-bottom: 16px; background: transparent; border: none; outline: none; font-size: 28px; font-weight: 600; color: var(--text-primary); }
.content-textarea { flex: 1; width: 100%; padding: 16px; background: var(--secondary-bg); border: 1px solid var(--border-color); border-radius: 8px; color: var(--text-primary); font-size: 14px; line-height: 1.8; resize: none; }
.content-textarea:focus { outline: none; border-color: var(--accent-gold); }
.editor-sidebar { width: 300px; background: var(--secondary-bg); border-left: 1px solid var(--border-color); padding: 16px; overflow-y: auto; }
.ai-panel h3 { font-size: 16px; margin-bottom: 16px; }
.provider-select { margin-bottom: 12px; }
.provider-select select { width: 100%; }
.style-select { margin-bottom: 12px; }
.style-select select { width: 100%; }
.ai-actions { display: grid; grid-template-columns: repeat(2, 1fr); gap: 8px; margin-bottom: 16px; }
.ai-btn { padding: 16px; background: var(--hover-bg); border: 1px solid var(--border-color); border-radius: 8px; color: var(--text-secondary); cursor: pointer; }
.ai-btn:hover { border-color: var(--accent-gold); }
.ai-result { padding: 12px; background: var(--hover-bg); border-radius: 8px; white-space: pre-wrap; }
.ai-loading { text-align: center; padding: 20px; color: var(--text-secondary); }

.modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.6); display: flex; align-items: center; justify-content: center; z-index: 200; }
.modal { background: var(--secondary-bg); border: 1px solid var(--border-color); border-radius: 12px; padding: 24px; width: 420px; max-width: 90vw; }
.modal h3 { font-size: 18px; margin-bottom: 20px; }
.form-group { margin-bottom: 16px; }
.form-group label { display: block; margin-bottom: 6px; color: var(--text-secondary); font-size: 14px; }
.form-group .input { width: 100%; padding: 10px 14px; background: var(--primary-bg); border: 1px solid var(--border-color); border-radius: 8px; color: var(--text-primary); font-size: 14px; }
.form-group .input:focus { outline: none; border-color: var(--accent-gold); }
.form-group input[type="checkbox"] { margin-right: 6px; }
.modal-actions { display: flex; gap: 8px; justify-content: flex-end; margin-top: 20px; }
.share-result { margin-top: 16px; padding: 12px; background: var(--hover-bg); border-radius: 8px; }
.share-result p { font-size: 14px; color: var(--text-secondary); margin-bottom: 6px; }
.share-result input { width: 100%; padding: 8px; background: var(--primary-bg); border: 1px solid var(--border-color); border-radius: 6px; color: var(--accent-gold); font-size: 13px; cursor: pointer; }

.dropdown-menu { position: absolute; top: 56px; right: 24px; background: var(--secondary-bg); border: 1px solid var(--border-color); border-radius: 8px; padding: 8px 0; min-width: 180px; z-index: 150; box-shadow: 0 8px 24px rgba(0,0,0,0.3); }
.dropdown-item { padding: 10px 16px; cursor: pointer; color: var(--text-secondary); font-size: 14px; }
.dropdown-item:hover { background: var(--hover-bg); color: var(--text-primary); }
.dropdown-item.danger { color: var(--danger); }
.dropdown-item.danger:hover { background: rgba(239,68,68,0.1); }
.dropdown-divider { height: 1px; background: var(--border-color); margin: 4px 0; }

.input { padding: 8px 12px; background: var(--primary-bg); border: 1px solid var(--border-color); border-radius: 8px; color: var(--text-primary); font-size: 14px; }
.input:focus { outline: none; border-color: var(--accent-gold); }
.btn-ghost { background: transparent; border: none; color: var(--text-secondary); cursor: pointer; padding: 8px 12px; border-radius: 6px; font-size: 14px; display: flex; align-items: center; gap: 6px; }
.btn-ghost:hover { background: var(--hover-bg); color: var(--text-primary); }
.btn-primary { padding: 8px 16px; background: var(--accent-gold); border: none; border-radius: 6px; color: var(--primary-bg); cursor: pointer; font-size: 14px; font-weight: 500; }
.btn-primary:disabled { opacity: 0.5; cursor: not-allowed; }
</style>