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
        <button class="btn btn-ghost dropdown-trigger" @click="showMoreMenu = !showMoreMenu"><i class="fas fa-ellipsis-h"></i></button>
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

    <!-- 版本历史面板 -->
    <div v-if="showVersionPanel" class="side-panel">
      <div class="panel-header">
        <h3>版本历史</h3>
        <button class="icon-btn" @click="showVersionPanel = false">&times;</button>
      </div>
      <div class="panel-body">
        <div v-if="versionsLoading" class="loading"><div class="spinner"></div></div>
        <div v-else-if="versions.length === 0" class="empty">暂无历史版本</div>
        <ul v-else class="version-list">
          <li v-for="v in versions" :key="v.id" class="version-item">
            <div class="version-info">
              <span class="version-num">v{{ v.version_number }}</span>
              <span class="version-time">{{ formatTime(v.created_at) }}</span>
            </div>
            <div class="version-desc">{{ v.change_description || '无描述' }}</div>
          </li>
        </ul>
      </div>
    </div>

    <!-- 附件面板 -->
    <div v-if="showAttachments" class="side-panel">
      <div class="panel-header">
        <h3>附件</h3>
        <button class="icon-btn" @click="showAttachments = false">&times;</button>
      </div>
      <div class="panel-body">
        <input type="file" @change="handleUpload" ref="fileInput" style="display:none">
        <button class="btn btn-secondary" @click="$refs.fileInput.click()">上传附件</button>
        <div v-if="attachments.length === 0" class="empty">暂无附件</div>
        <ul v-else class="attachment-list">
          <li v-for="a in attachments" :key="a.id" class="attachment-item">
            <span class="attachment-name">{{ a.filename || a.original_name }}</span>
            <button class="icon-btn" @click="deleteAttachment(a.id)">&times;</button>
          </li>
        </ul>
      </div>
    </div>

    <!-- 评论面板 -->
    <div v-if="showComments" class="side-panel">
      <div class="panel-header">
        <h3>评论</h3>
        <button class="icon-btn" @click="showComments = false">&times;</button>
      </div>
      <div class="panel-body">
        <div class="comment-input">
          <textarea v-model="newComment" placeholder="写下你的评论..."></textarea>
          <button class="btn btn-primary" @click="addComment" :disabled="!newComment.trim()">发送</button>
        </div>
        <div v-if="comments.length === 0" class="empty">暂无评论</div>
        <ul v-else class="comment-list">
          <li v-for="c in comments" :key="c.id" class="comment-item">
            <div class="comment-author">{{ c.username }}</div>
            <div class="comment-content">{{ c.content }}</div>
            <div class="comment-time">{{ formatTime(c.created_at) }}</div>
          </li>
        </ul>
      </div>
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
import { ref, onMounted, onUnmounted, watch, computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useNotesStore } from '@/stores/notes'
import { useUserStore } from '@/stores/user'
import { aiApi, shareApi, versionApi, attachmentsApi, commentsApi } from '@/api'
import dayjs from 'dayjs'

const route = useRoute()
const router = useRouter()
const notesStore = useNotesStore()
const userStore = useUserStore()
// 自动保存开关：跟随用户设置（默认开启）
const autoSaveEnabled = computed(() => userStore.settings?.auto_save !== false)
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
// 面板相关状态
const versions = ref([])
const versionsLoading = ref(false)
const attachments = ref([])
const comments = ref([])
const newComment = ref('')
const fileInput = ref(null)

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
  // 切换笔记时清除尚未触发的自动保存定时器，避免保存到错误的笔记
  if (autoTimer) { clearTimeout(autoTimer); autoTimer = null }
  if (newId) {
    noteId.value = newId
    loadNote()
  }
})

// 面板展开时按需加载对应数据
watch([showVersionPanel, showAttachments, showComments], ([v, a, c]) => {
  if (v) loadVersions()
  if (a) loadAttachments()
  if (c) loadComments()
})

onUnmounted(() => {
  if (autoTimer) { clearTimeout(autoTimer); autoTimer = null }
})

// 点击外部关闭更多菜单
function handleClickOutside(e) {
  const menu = document.querySelector('.dropdown-menu')
  const trigger = document.querySelector('.dropdown-trigger')
  if (showMoreMenu.value && menu && !menu.contains(e.target) && !trigger?.contains(e.target)) {
    showMoreMenu.value = false
  }
}
onMounted(() => document.addEventListener('click', handleClickOutside))
onUnmounted(() => document.removeEventListener('click', handleClickOutside))

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
  // 用户在设置中关闭自动保存时，跳过定时保存
  if (!autoSaveEnabled.value) return
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

// 时间格式化
function formatTime(t) {
  return t ? dayjs(t).format('YYYY-MM-DD HH:mm') : ''
}

// 加载版本历史
async function loadVersions() {
  if (!noteId.value || noteId.value === 'new') return
  versionsLoading.value = true
  try {
    const r = await versionApi.list(noteId.value)
    if (r.success) versions.value = r.versions || r.data || []
  } finally { versionsLoading.value = false }
}

// 加载附件列表
async function loadAttachments() {
  if (!noteId.value || noteId.value === 'new') return
  const r = await attachmentsApi.list(noteId.value)
  if (r.success) attachments.value = r.attachments || r.data || []
}

// 加载评论列表
async function loadComments() {
  if (!noteId.value || noteId.value === 'new') return
  const r = await commentsApi.list(noteId.value)
  if (r.success) comments.value = r.comments || r.data || []
}

// 上传附件
async function handleUpload(e) {
  const file = e.target.files[0]
  if (!file) return
  try {
    const r = await attachmentsApi.upload(noteId.value, file)
    if (r.success) {
      await loadAttachments()
      e.target.value = ''
    } else {
      alert(r.message || '上传失败')
    }
  } catch (err) { /* 忽略网络错误 */ }
}

// 删除附件
async function deleteAttachment(id) {
  if (!confirm('确定删除此附件？')) return
  const r = await attachmentsApi.delete(id)
  if (r.success) await loadAttachments()
  else alert(r.message || '删除失败')
}

// 添加评论
async function addComment() {
  if (!newComment.value.trim()) return
  const r = await commentsApi.add(noteId.value, newComment.value)
  if (r.success) {
    newComment.value = ''
    await loadComments()
  } else {
    alert(r.message || '评论失败')
  }
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

/* 侧边面板（版本/附件/评论） */
.side-panel { margin: 16px 24px; background: var(--secondary-bg); border: 1px solid var(--border-color); border-radius: 8px; overflow: hidden; }
.panel-header { display: flex; justify-content: space-between; align-items: center; padding: 12px 16px; border-bottom: 1px solid var(--border-color); }
.panel-header h3 { font-size: 16px; margin: 0; }
.panel-body { padding: 16px; }
.icon-btn { background: transparent; border: none; color: var(--text-secondary); cursor: pointer; font-size: 18px; padding: 4px 8px; border-radius: 4px; line-height: 1; }
.icon-btn:hover { background: var(--hover-bg); color: var(--text-primary); }
.btn-secondary { padding: 8px 16px; background: var(--hover-bg); border: 1px solid var(--border-color); border-radius: 6px; color: var(--text-primary); cursor: pointer; font-size: 14px; margin-bottom: 12px; }
.btn-secondary:hover { border-color: var(--accent-gold); }
.loading { display: flex; justify-content: center; padding: 24px; }
.spinner { width: 24px; height: 24px; border: 2px solid var(--border-color); border-top-color: var(--accent-gold); border-radius: 50%; animation: spin 0.8s linear infinite; }
@keyframes spin { to { transform: rotate(360deg); } }
.empty { text-align: center; color: var(--text-muted); padding: 24px; font-size: 14px; }
.version-list, .attachment-list, .comment-list { list-style: none; padding: 0; margin: 0; }
.version-item { padding: 10px 0; border-bottom: 1px solid var(--border-color); }
.version-item:last-child { border-bottom: none; }
.version-info { display: flex; gap: 12px; align-items: center; margin-bottom: 4px; }
.version-num { font-weight: 600; color: var(--accent-gold); font-size: 13px; }
.version-time { color: var(--text-muted); font-size: 12px; }
.version-desc { color: var(--text-secondary); font-size: 13px; }
.attachment-item { display: flex; justify-content: space-between; align-items: center; padding: 8px 0; border-bottom: 1px solid var(--border-color); }
.attachment-item:last-child { border-bottom: none; }
.attachment-name { color: var(--text-primary); font-size: 14px; }
.comment-input { display: flex; flex-direction: column; gap: 8px; margin-bottom: 16px; }
.comment-input textarea { width: 100%; padding: 8px 12px; background: var(--primary-bg); border: 1px solid var(--border-color); border-radius: 8px; color: var(--text-primary); font-size: 14px; resize: vertical; min-height: 60px; box-sizing: border-box; }
.comment-input textarea:focus { outline: none; border-color: var(--accent-gold); }
.comment-input .btn-primary { align-self: flex-end; }
.comment-item { padding: 12px 0; border-bottom: 1px solid var(--border-color); }
.comment-item:last-child { border-bottom: none; }
.comment-author { font-weight: 600; color: var(--text-primary); font-size: 14px; margin-bottom: 4px; }
.comment-content { color: var(--text-secondary); font-size: 14px; margin-bottom: 4px; white-space: pre-wrap; }
.comment-time { color: var(--text-muted); font-size: 12px; }
</style>