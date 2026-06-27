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
        <div class="form-group"><label>API密钥</label><input type="password" v-model="form.chatgpt_api_key" class="input"></div>
        <div class="form-group"><label>API地址</label><input type="text" v-model="form.chatgpt_api_url" class="input" placeholder="https://api.openai.com/v1"></div>
      </fieldset>
      <fieldset><legend>豆包配置</legend>
        <div class="form-group"><label>API密钥</label><input type="password" v-model="form.doubao_api_key" class="input"></div>
        <div class="form-group"><label>API地址</label><input type="text" v-model="form.doubao_api_url" class="input"></div>
      </fieldset>
      <fieldset><legend>Claude配置</legend>
        <div class="form-group"><label>API密钥</label><input type="password" v-model="form.claude_api_key" class="input"></div>
        <div class="form-group"><label>API地址</label><input type="text" v-model="form.claude_api_url" class="input"></div>
      </fieldset>
      <fieldset><legend>自定义OpenAI配置</legend>
        <div class="form-group"><label>自定义名称</label><input type="text" v-model="form.custom_openai_name" class="input" placeholder="我的AI服务"></div>
        <div class="form-group"><label>API密钥</label><input type="password" v-model="form.custom_openai_api_key" class="input"></div>
        <div class="form-group"><label>API地址</label><input type="text" v-model="form.custom_openai_api_url" class="input"></div>
        <div class="form-group"><label>模型名称</label><input type="text" v-model="form.custom_openai_model" class="input" placeholder="gpt-4"></div>
      </fieldset>
      <fieldset><legend>系统参数</legend>
        <div class="form-group"><label>最大上传大小 (MB)</label><input type="number" v-model="form.max_upload_size" class="input"></div>
        <div class="form-group"><label>最大登录尝试</label><input type="number" v-model="form.max_login_attempts" class="input"></div>
        <div class="form-group"><label>登录锁定时长 (秒)</label><input type="number" v-model="form.login_lockout_duration" class="input"></div>
      </fieldset>
      <button class="btn btn-primary" @click="saveSettings" :disabled="saving">{{ saving ? '保存中...' : '保存设置' }}</button>
    </div>
  </div>
</template>
<script setup>
import { ref, onMounted } from 'vue'
import { adminApi } from '@/api'

const form = ref({})
const loading = ref(false)
const saving = ref(false)

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
</style>