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
    <h2>系统设置</h2>
    <div v-if="loading" class="loading"><i class="fas fa-spinner fa-spin"></i></div>
    <div v-else class="settings-form">
      <div class="form-group"><label>站点名称</label><input type="text" v-model="form.site_name" class="input"></div>
      <div class="form-group"><label>站点描述</label><input type="text" v-model="form.site_description" class="input"></div>
      <div class="form-group"><label>注册模式</label>
        <select v-model="form.registration_mode" class="input">
          <option value="open">开放注册</option>
          <option value="invite">邀请码注册</option>
          <option value="closed">关闭注册</option>
        </select>
      </div>
      <div class="form-group"><label>默认AI提供商</label>
        <select v-model="form.default_ai_provider" class="input">
          <option value="chatgpt">ChatGPT</option>
          <option value="doubao">豆包</option>
          <option value="claude">Claude</option>
        </select>
      </div>
      <fieldset><legend>ChatGPT配置</legend>
        <div class="form-group"><label>API密钥</label>
          <div class="input-with-toggle">
            <input :type="showApiKeys.chatgpt ? 'text' : 'password'" v-model="form.chatgpt_api_key" class="input">
            <button type="button" class="toggle-btn" @click="toggleApiKey('chatgpt')">{{ showApiKeys.chatgpt ? '隐藏' : '显示' }}</button>
          </div>
        </div>
        <div class="form-group"><label>API地址</label><input type="text" v-model="form.chatgpt_api_url" class="input" placeholder="https://api.openai.com/v1"></div>
      </fieldset>
      <fieldset><legend>豆包配置</legend>
        <div class="form-group"><label>API密钥</label>
          <div class="input-with-toggle">
            <input :type="showApiKeys.doubao ? 'text' : 'password'" v-model="form.doubao_api_key" class="input">
            <button type="button" class="toggle-btn" @click="toggleApiKey('doubao')">{{ showApiKeys.doubao ? '隐藏' : '显示' }}</button>
          </div>
        </div>
        <div class="form-group"><label>API地址</label><input type="text" v-model="form.doubao_api_url" class="input"></div>
      </fieldset>
      <fieldset><legend>Claude配置</legend>
        <div class="form-group"><label>API密钥</label>
          <div class="input-with-toggle">
            <input :type="showApiKeys.claude ? 'text' : 'password'" v-model="form.claude_api_key" class="input">
            <button type="button" class="toggle-btn" @click="toggleApiKey('claude')">{{ showApiKeys.claude ? '隐藏' : '显示' }}</button>
          </div>
        </div>
        <div class="form-group"><label>API地址</label><input type="text" v-model="form.claude_api_url" class="input"></div>
      </fieldset>
      <fieldset><legend>自定义OpenAI配置</legend>
        <div class="form-group"><label>自定义名称</label><input type="text" v-model="form.custom_openai_name" class="input" placeholder="我的AI服务"></div>
        <div class="form-group"><label>API密钥</label>
          <div class="input-with-toggle">
            <input :type="showApiKeys.custom_openai ? 'text' : 'password'" v-model="form.custom_openai_api_key" class="input">
            <button type="button" class="toggle-btn" @click="toggleApiKey('custom_openai')">{{ showApiKeys.custom_openai ? '隐藏' : '显示' }}</button>
          </div>
        </div>
        <div class="form-group"><label>API地址</label><input type="text" v-model="form.custom_openai_api_url" class="input"></div>
        <div class="form-group"><label>模型名称</label><input type="text" v-model="form.custom_openai_model" class="input" placeholder="gpt-4"></div>
      </fieldset>
      <fieldset><legend>系统参数</legend>
        <div class="form-group"><label>最大上传大小 (MB)</label><input type="number" v-model.number="form.max_upload_size" min="1" max="100" class="input"><small class="form-hint">范围 1-100 MB</small></div>
        <div class="form-group"><label>最大登录尝试</label><input type="number" v-model.number="form.max_login_attempts" min="1" max="10" class="input"><small class="form-hint">范围 1-10 次</small></div>
        <div class="form-group"><label>登录锁定时长 (秒)</label><input type="number" v-model.number="form.login_lockout_duration" min="60" max="86400" class="input"><small class="form-hint">范围 60-86400 秒</small></div>
      </fieldset>
      <button class="btn btn-primary" @click="saveSettings" :disabled="saving">{{ saving ? '保存中...' : '保存设置' }}</button>
    </div>
  </div>
</template>
<script setup>
import { ref, reactive, onMounted } from 'vue'
import { adminApi } from '@/api'

const form = ref({})
const loading = ref(false)
const saving = ref(false)
// 各 AI 密钥的显隐状态
const showApiKeys = reactive({ chatgpt: false, doubao: false, claude: false, custom_openai: false })
function toggleApiKey(name) { showApiKeys[name] = !showApiKeys[name] }

const defaultValues = {
  registration_mode: 'invite', site_name: 'PHP笔记系统', site_description: '',
  default_ai_provider: 'chatgpt', chatgpt_api_key: '', chatgpt_api_url: 'https://api.openai.com/v1',
  doubao_api_key: '', doubao_api_url: '', claude_api_key: '', claude_api_url: '',
  custom_openai_name: '', custom_openai_api_key: '', custom_openai_api_url: '', custom_openai_model: '',
  max_upload_size: 10, max_login_attempts: 5, login_lockout_duration: 900
}

onMounted(async () => {
  loading.value = true
  const r = await adminApi.getSettings()
  if (r.success) form.value = { ...defaultValues, ...r.settings }
  loading.value = false
})

async function saveSettings() {
  // 数值范围校验
  if (form.value.max_upload_size < 1 || form.value.max_upload_size > 100) {
    alert('上传大小需在 1-100 MB 之间')
    return
  }
  if (form.value.max_login_attempts < 1 || form.value.max_login_attempts > 10) {
    alert('最大登录尝试需在 1-10 之间')
    return
  }
  if (form.value.login_lockout_duration < 60 || form.value.login_lockout_duration > 86400) {
    alert('登录锁定时长需在 60-86400 秒之间')
    return
  }
  saving.value = true
  const r = await adminApi.saveSettings(form.value)
  saving.value = false
  alert(r.success ? '设置已保存' : r.message)
}
</script>
<style scoped>
.admin-nav { display: flex; gap: 8px; margin-bottom: 24px; flex-wrap: wrap; }
.nav-btn { padding: 10px 20px; background: var(--secondary-bg); border: 1px solid var(--border-color); border-radius: 8px; color: var(--text-secondary); text-decoration: none; font-size: 14px; }
.nav-btn:hover { border-color: var(--accent-gold); }
:deep(.router-link-exact-active) { border-color: var(--accent-gold); color: var(--accent-gold); }
h2 { font-size: 20px; margin-bottom: 20px; }
.loading { text-align: center; padding: 40px; color: var(--text-muted); }
.settings-form { max-width: 600px; }
.form-group { margin-bottom: 16px; }
.form-group label { display: block; margin-bottom: 6px; font-size: 13px; color: var(--text-secondary); }
fieldset { border: 1px solid var(--border-color); border-radius: 8px; padding: 16px; margin-bottom: 16px; }
legend { font-size: 14px; font-weight: 500; color: var(--accent-gold); padding: 0 8px; }
.input-with-toggle { position: relative; display: flex; align-items: center; }
.input-with-toggle .input { flex: 1; padding-right: 56px; }
.toggle-btn { position: absolute; right: 8px; top: 50%; transform: translateY(-50%); background: transparent; border: none; color: var(--text-secondary); cursor: pointer; font-size: 12px; padding: 4px 8px; }
.toggle-btn:hover { color: var(--accent-gold); }
.form-hint { display: block; margin-top: 4px; font-size: 12px; color: var(--text-muted); }
</style>