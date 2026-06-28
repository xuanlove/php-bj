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
        <button class="btn btn-ghost" @click="toggleViewMode" :title="viewMode === 'edit' ? '预览' : '编辑'">
          <i :class="viewMode === 'edit' ? 'fas fa-eye' : 'fas fa-pen'"></i>
        </button>
        <button class="btn btn-ghost" data-more-btn @click="showMoreMenu = !showMoreMenu"><i class="fas fa-ellipsis-h"></i></button>
        <button class="btn btn-primary" @click="saveNote" :disabled="saving">{{ saving ? '保存中...' : '保存' }}</button>
      </div>
    </div>
    <div class="editor-container">
      <div class="editor-main">
        <input v-model="title" class="title-input" placeholder="笔记标题" @input="autoSave">
        <div v-if="viewMode === 'split'" class="split-pane">
          <textarea v-model="content" class="content-textarea split-left" placeholder="开始编辑笔记..." @input="autoSave"></textarea>
          <div class="markdown-preview split-right" v-html="renderedContent"></div>
        </div>
        <div v-else-if="viewMode === 'preview'" class="markdown-preview full-preview" v-html="renderedContent"></div>
        <textarea v-else v-model="content" class="content-textarea" placeholder="开始编辑笔记..." @input="autoSave"></textarea>
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
          <button class="btn btn-primary" @click="createShare">创建分享</button>
        </div>
        <div v-if="shareUrl" class="share-result">
          <p>分享链接:</p>
          <input :value="shareUrl" readonly class="input" @click="$event.target.select()">
        </div>
      </div>
    </div>

    <!-- 更多菜单 -->
    <div v-if="showMoreMenu" class="dropdown-menu" data-more-menu>
      <div class="dropdown-item" @click="exportNote('markdown')">导出 Markdown</div>
      <div class="dropdown-item" @click="exportNote('html')">导出 HTML</div>
      <div class="dropdown-item" @click="exportNote('json')">导出 JSON</div>
      <div class="dropdown-divider"></div>
      <div class="dropdown-item danger" @click="deleteNote">删除笔记</div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useNotesStore } from '@/stores/notes'
import { aiApi, shareApi } from '@/api'
import { renderMarkdown } from '@/utils/markdown'

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
// 视图模式：edit(纯编辑) / split(分屏) / preview(纯预览)
const viewMode = ref('edit')
const renderedContent = computed(() => renderMarkdown(content.value))

function toggleViewMode() {
  // edit -> split -> preview -> edit
  const next = { edit: 'split', split: 'preview', preview: 'edit' }
  viewMode.value = next[viewMode.value] || 'edit'
}
const aiResult = ref('')
const aiLoading = ref(false)
const aiProvider = ref('chatgpt')
const aiStyle = ref('professional')
const aiActionType = ref('')
const sharePassword = ref('')
const shareExpires = ref(0)
const shareAllowDownload = ref(true)
const shareUrl = ref('')
let autoTimer = null

async function loadNote() {
  if (noteId.value && noteId.value !== 'new') {
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
  document.addEventListener('click', handleOutsideClick)
})

// 切换笔记 id 时重新加载(同组件复用场景)
watch(() => route.params.id, (newId) => {
  if (newId && newId !== noteId.value) {
    noteId.value = newId
    title.value = ''
    content.value = ''
    loadNote()
  }
})

function handleOutsideClick(e) {
  if (showMoreMenu.value && !e.target.closest('[data-more-menu]') && !e.target.closest('[data-more-btn]')) {
    showMoreMenu.value = false
  }
}

onUnmounted(() => {
  if (autoTimer) clearTimeout(autoTimer)
  document.removeEventListener('click', handleOutsideClick)
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
}

async function exportNote(format) {
  const r = await notesStore.exportNote(noteId.value, format)
  if (r.success) {
    const blob = new Blob([r.content], { type: r.mime_type })
    const url = URL.createObjectURL(blob)
    const a = document.createElement('a')
    a.href = url
    a.download = r.filename
    a.click()
    URL.revokeObjectURL(url)
  }
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

/* 分屏与预览模式 */
.split-pane { flex: 1; display: flex; gap: 16px; min-height: 0; }
.split-pane .split-left { flex: 1; min-height: 0; }
.split-pane .split-right { flex: 1; overflow-y: auto; padding: 16px; background: var(--secondary-bg); border: 1px solid var(--border-color); border-radius: 8px; }
.full-preview { flex: 1; overflow-y: auto; padding: 16px; background: var(--secondary-bg); border: 1px solid var(--border-color); border-radius: 8px; }

/* Markdown 预览内容样式 */
.markdown-preview :deep(h1) { font-size: 24px; margin: 16px 0 12px; padding-bottom: 8px; border-bottom: 1px solid var(--border-color); }
.markdown-preview :deep(h2) { font-size: 20px; margin: 14px 0 10px; }
.markdown-preview :deep(h3) { font-size: 17px; margin: 12px 0 8px; }
.markdown-preview :deep(h4) { font-size: 15px; margin: 10px 0 6px; }
.markdown-preview :deep(p) { margin: 8px 0; line-height: 1.8; }
.markdown-preview :deep(ul), .markdown-preview :deep(ol) { margin: 8px 0; padding-left: 24px; }
.markdown-preview :deep(li) { margin: 4px 0; line-height: 1.7; }
.markdown-preview :deep(blockquote) { margin: 10px 0; padding: 8px 16px; border-left: 4px solid var(--accent-gold); background: var(--hover-bg); color: var(--text-secondary); border-radius: 0 6px 6px 0; }
.markdown-preview :deep(a) { color: var(--accent-gold); text-decoration: none; }
.markdown-preview :deep(a:hover) { text-decoration: underline; }
.markdown-preview :deep(table) { width: 100%; border-collapse: collapse; margin: 12px 0; }
.markdown-preview :deep(th), .markdown-preview :deep(td) { padding: 8px 12px; border: 1px solid var(--border-color); text-align: left; }
.markdown-preview :deep(th) { background: var(--hover-bg); font-weight: 600; }
.markdown-preview :deep(img) { max-width: 100%; border-radius: 8px; }
.markdown-preview :deep(hr) { border: none; border-top: 1px solid var(--border-color); margin: 16px 0; }
.markdown-preview :deep(pre) { margin: 12px 0; padding: 14px; background: #0d1117; border: 1px solid var(--border-color); border-radius: 8px; overflow-x: auto; }
.markdown-preview :deep(pre code) { font-family: 'Source Code Pro', 'Consolas', 'Monaco', monospace; font-size: 13px; line-height: 1.6; background: transparent; padding: 0; white-space: pre; }
.markdown-preview :deep(code.hljs.inline) { font-family: 'Source Code Pro', 'Consolas', monospace; font-size: 0.9em; padding: 2px 6px; background: var(--hover-bg); border-radius: 4px; color: var(--accent-gold); }
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