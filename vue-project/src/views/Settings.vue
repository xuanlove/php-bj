<template>
  <div class="page">
    <h1>个人设置</h1>

    <div class="section">
      <h2>外观</h2>
      <label>主题:</label>
      <select v-model="theme" class="input" @change="applyTheme">
        <option value="dark">深色</option>
        <option value="light">浅色</option>
        <option value="auto">跟随系统</option>
      </select>
    </div>

    <div class="section">
      <h2>编辑器</h2>
      <div class="field">
        <label>字号:</label>
        <input type="number" v-model="fontSize" class="input" style="width:80px" min="12" max="24">
      </div>
      <div class="field">
        <label>Tab 大小:</label>
        <select v-model="tabSize" class="input" style="width:100px">
          <option :value="2">2</option>
          <option :value="4">4</option>
          <option :value="8">8</option>
        </select>
      </div>
      <div class="field">
        <label><input type="checkbox" v-model="autoSave"> 自动保存</label>
      </div>
    </div>

    <div class="section">
      <h2>两步验证 (2FA)</h2>
      <div v-if="twoFactorStatus === null" class="loading-text">加载中...</div>
      <div v-else-if="twoFactorEnabled">
        <p class="status-text success">两步验证已启用</p>
        <div class="form-group">
          <label>验证码:</label>
          <input v-model="twoFactorCode" type="text" class="input" placeholder="输入6位验证码" maxlength="6">
        </div>
        <button class="btn btn-danger" @click="disable2FA" :disabled="twoFactorLoading">禁用两步验证</button>
      </div>
      <div v-else>
        <p class="status-text">两步验证未启用</p>
        <button class="btn btn-primary" @click="generate2FA" :disabled="twoFactorLoading">生成验证密钥</button>
        <div v-if="twoFactorSecret" class="twofa-setup">
          <p>密钥: <code>{{ twoFactorSecret }}</code></p>
          <div class="form-group">
            <label>验证码:</label>
            <input v-model="twoFactorCode" type="text" class="input" placeholder="输入6位验证码" maxlength="6">
          </div>
          <button class="btn btn-primary" @click="enable2FA" :disabled="twoFactorLoading">启用两步验证</button>
        </div>
      </div>
    </div>

    <div class="section">
      <h2>API 密钥</h2>
      <div class="api-keys-list" v-if="apiKeys.length > 0">
        <div v-for="key in apiKeys" :key="key.id" class="api-key-item">
          <div class="key-info">
            <span class="key-name">{{ key.name || key.key_name }}</span>
            <span class="key-prefix">{{ key.key_prefix || 'nk_****' }}</span>
          </div>
          <span class="key-status" :class="key.status">{{ key.status === 'active' ? '启用' : '禁用' }}</span>
          <button class="btn btn-ghost" @click="toggleApiKey(key.id)" title="切换状态">
            <i :class="key.status === 'active' ? 'fas fa-pause' : 'fas fa-play'"></i>
          </button>
          <button class="btn btn-ghost" @click="deleteApiKey(key.id)" title="删除">
            <i class="fas fa-trash"></i>
          </button>
        </div>
      </div>
      <p v-else class="empty-text">暂无 API 密钥</p>
      <div class="create-key">
        <input v-model="newKeyName" class="input" placeholder="密钥名称" style="flex:1">
        <button class="btn btn-primary" @click="createApiKey" :disabled="!newKeyName.trim()">创建密钥</button>
      </div>
      <div v-if="newKeyValue" class="new-key-result">
        <p>新密钥 (仅显示一次，请妥善保存):</p>
        <input :value="newKeyValue" readonly class="input" @click="$event.target.select()">
      </div>
    </div>

    <button class="btn btn-primary" @click="saveSettings" :disabled="saving">{{ saving ? '保存中...' : '保存设置' }}</button>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { useUserStore } from '@/stores/user'
import { twoFactorApi, apiKeysApi } from '@/api'

const userStore = useUserStore()
const theme = ref('dark')
const fontSize = ref(14)
const tabSize = ref(4)
const autoSave = ref(true)
const saving = ref(false)

const twoFactorEnabled = ref(false)
const twoFactorStatus = ref(null)
const twoFactorSecret = ref('')
const twoFactorCode = ref('')
const twoFactorLoading = ref(false)

const apiKeys = ref([])
const newKeyName = ref('')
const newKeyValue = ref('')

onMounted(async () => {
  await userStore.fetchSettings()
  if (userStore.settings) {
    theme.value = userStore.settings.theme || 'dark'
    fontSize.value = userStore.settings.editor_font_size || 14
    tabSize.value = userStore.settings.editor_tab_size || 4
    autoSave.value = userStore.settings.auto_save ?? true
  }
  applyThemeToDOM(theme.value)
  fetchTwoFactorStatus()
  fetchApiKeys()
})

function applyThemeToDOM(theme) {
  // 移除旧主题 class
  document.body.classList.remove('light-theme', 'theme-default', 'theme-forest', 'theme-ocean', 'theme-sunset', 'theme-midnight', 'theme-minimal')

  // 处理 auto：根据系统偏好决定明暗
  let actualTheme = theme
  if (theme === 'auto') {
    actualTheme = window.matchMedia('(prefers-color-scheme: light)').matches ? 'light' : 'dark'
  }

  // 应用明暗模式（theme.css 用 body.light-theme 区分）
  if (actualTheme === 'light') {
    document.body.classList.add('light-theme')
  }
  // dark 模式不添加 light-theme（theme.css 默认深色）

  // 应用具体主题（如果非 default，加载对应 theme.css）
  if (theme !== 'default' && theme !== 'auto' && theme !== 'light' && theme !== 'dark') {
    document.body.classList.add('theme-' + theme)
  }
}

async function applyTheme() {
  applyThemeToDOM(theme.value)
  await userStore.updateSettings({ theme: theme.value })
}

async function saveSettings() {
  saving.value = true
  try {
    const r = await userStore.updateSettings({
      theme: theme.value,
      editor_font_size: fontSize.value,
      editor_tab_size: tabSize.value,
      auto_save: autoSave.value
    })
    // 兼容 r.success 与 r.data?.success 两种响应结构
    if (r && (r.success || r.data?.success)) {
      alert('设置已保存')
    } else {
      alert('保存失败：' + (r?.message || '未知错误'))
    }
  } catch (e) {
    // 捕获网络异常，避免无反馈
    alert('保存失败：' + (e.message || '网络错误'))
  } finally {
    saving.value = false
  }
}

async function fetchTwoFactorStatus() {
  try {
    const r = await twoFactorApi.status()
    if (r.success) {
      twoFactorEnabled.value = r.enabled
    }
    twoFactorStatus.value = 'loaded'
  } catch (e) { twoFactorStatus.value = 'error' }
}

async function generate2FA() {
  twoFactorLoading.value = true
  try {
    const r = await twoFactorApi.generate()
    if (r && (r.success || r.data?.success)) {
      // 兼容不同响应结构
      twoFactorSecret.value = r.secret || r.data?.secret
    } else {
      alert('生成失败：' + (r?.message || '未知错误'))
    }
  } catch (e) {
    // 网络异常时给出明确提示
    alert('生成失败：' + (e.message || '网络错误'))
  } finally {
    twoFactorLoading.value = false
  }
}

async function enable2FA() {
  if (!twoFactorCode.value) { alert('请输入验证码'); return }
  twoFactorLoading.value = true
  try {
    const r = await twoFactorApi.enable(twoFactorSecret.value, twoFactorCode.value)
    if (r.success) {
      twoFactorEnabled.value = true
      twoFactorSecret.value = ''
      twoFactorCode.value = ''
      alert(r.message)
    } else alert(r.message)
  } finally { twoFactorLoading.value = false }
}

async function disable2FA() {
  if (!twoFactorCode.value) { alert('请输入验证码'); return }
  twoFactorLoading.value = true
  try {
    const r = await twoFactorApi.disable(twoFactorCode.value)
    if (r.success) {
      twoFactorEnabled.value = false
      twoFactorCode.value = ''
      alert(r.message)
    } else alert(r.message)
  } finally { twoFactorLoading.value = false }
}

async function fetchApiKeys() {
  try {
    const r = await apiKeysApi.list()
    if (r.success) apiKeys.value = r.keys || []
  } catch (e) { /* ignore */ }
}

async function createApiKey() {
  if (!newKeyName.value.trim()) return
  try {
    const r = await apiKeysApi.create(newKeyName.value, ['read', 'write'])
    if (r && (r.success || r.data?.success)) {
      newKeyValue.value = r.key || r.api_key || r.data?.key
      newKeyName.value = ''
      await fetchApiKeys()
    } else {
      // 失败时清空已写入的本地值，避免前端展示与后端状态不一致
      newKeyValue.value = ''
      alert('创建失败：' + (r?.message || '未知错误'))
    }
  } catch (e) {
    // 异常情况下回滚本地状态，防止脏数据残留
    newKeyValue.value = ''
    alert('创建失败：' + (e.message || '网络错误'))
  }
}

async function toggleApiKey(id) {
  try {
    const r = await apiKeysApi.toggle(id)
    if (r.success) fetchApiKeys()
    else alert(r.message)
  } catch (e) { alert('操作失败') }
}

async function deleteApiKey(id) {
  if (!confirm('确定删除此 API 密钥吗？')) return
  try {
    const r = await apiKeysApi.delete(id)
    if (r.success) { apiKeys.value = apiKeys.value.filter(k => k.id !== id) }
    else alert(r.message)
  } catch (e) { alert('删除失败') }
}
</script>

<style scoped>
.page { max-width: 700px; margin: 0 auto; padding: 24px; }
.page h1 { font-size: 24px; margin-bottom: 24px; }
.section { background: var(--secondary-bg); border: 1px solid var(--border-color); border-radius: 12px; padding: 20px; margin-bottom: 20px; }
.section h2 { font-size: 16px; margin-bottom: 16px; }
.field { margin-bottom: 12px; display: flex; align-items: center; gap: 12px; }
.field label { color: var(--text-secondary); font-size: 14px; }
.form-group { margin-bottom: 12px; }
.form-group label { display: block; margin-bottom: 6px; color: var(--text-secondary); font-size: 14px; }
.status-text { font-size: 14px; margin-bottom: 12px; color: var(--text-secondary); }
.status-text.success { color: #4caf50; }
.loading-text { color: var(--text-muted); font-size: 14px; }
.twofa-setup { margin-top: 12px; }
.twofa-setup code { display: block; padding: 8px; background: var(--primary-bg); border-radius: 6px; margin: 8px 0; word-break: break-all; }

.api-keys-list { margin-bottom: 16px; }
.api-key-item { display: flex; align-items: center; gap: 12px; padding: 10px; background: var(--primary-bg); border-radius: 8px; margin-bottom: 8px; }
.key-info { flex: 1; overflow: hidden; }
.key-name { display: block; font-size: 14px; }
.key-prefix { font-size: 12px; color: var(--text-muted); }
.key-status { font-size: 12px; padding: 2px 8px; border-radius: 10px; }
.key-status.active { background: rgba(76,175,80,0.15); color: #4caf50; }
.key-status.revoked { background: rgba(239,68,68,0.15); color: #ef4444; }
.create-key { display: flex; gap: 8px; }
.new-key-result { margin-top: 12px; padding: 12px; background: var(--hover-bg); border-radius: 8px; }
.new-key-result p { font-size: 14px; color: var(--accent-gold); margin-bottom: 6px; }
.new-key-result input { width: 100%; padding: 8px; background: var(--primary-bg); border: 1px solid var(--border-color); border-radius: 6px; color: var(--accent-gold); font-size: 13px; cursor: pointer; }
.empty-text { font-size: 14px; color: var(--text-muted); margin-bottom: 16px; }

.btn-ghost { background: transparent; border: none; color: var(--text-secondary); cursor: pointer; padding: 6px 12px; border-radius: 6px; font-size: 14px; }
.btn-ghost:hover { background: var(--hover-bg); color: var(--text-primary); }
.btn-ghost:focus { outline: 2px solid var(--accent-gold); outline-offset: 2px; }
.btn-ghost:focus:not(:focus-visible) { outline: none; }
.btn-primary { padding: 10px 20px; background: var(--accent-gold); border: none; border-radius: 8px; color: var(--primary-bg); cursor: pointer; font-size: 14px; font-weight: 500; }
.btn-primary:disabled { opacity: 0.5; cursor: not-allowed; }
.btn-danger { padding: 10px 20px; background: #ef4444; border: none; border-radius: 8px; color: white; cursor: pointer; font-size: 14px; }
.btn-danger:disabled { opacity: 0.5; cursor: not-allowed; }
.input { padding: 8px 12px; background: var(--primary-bg); border: 1px solid var(--border-color); border-radius: 8px; color: var(--text-primary); font-size: 14px; }
.input:focus { outline: none; border-color: var(--accent-gold); }
</style>