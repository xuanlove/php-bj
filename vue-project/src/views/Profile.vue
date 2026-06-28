<template>
  <div class="page">
    <h1>个人资料</h1>
    <div class="profile-card">
      <div class="avatar-section">
        <div class="avatar">{{ userInitials }}</div>
        <h2>{{ user?.username }}</h2>
        <p>{{ user?.email }}</p>
        <small>角色: {{ user?.role === 'admin' ? '管理员' : '用户' }}</small>
      </div>
    </div>

    <div class="section">
      <h3>基本信息</h3>
      <div class="form-group"><label>用户名</label><input type="text" v-model="form.username" class="input" disabled></div>
      <div class="form-group"><label>邮箱</label><input type="email" v-model="form.email" class="input" @change="dirty = true"></div>
      <div class="form-group"><label>姓名</label><input type="text" v-model="form.full_name" class="input" @change="dirty = true"></div>
      <button class="btn btn-primary" @click="saveProfile" :disabled="!dirty || saving">{{ saving ? '保存中...' : '保存修改' }}</button>
    </div>

    <div class="section">
      <h3>修改密码</h3>
      <div class="form-group"><label>当前密码</label>
        <div class="password-field">
          <input :type="showOldPassword ? 'text' : 'password'" v-model="pw.old" class="input">
          <button type="button" class="password-toggle" @click="showOldPassword = !showOldPassword" :aria-label="showOldPassword ? '隐藏密码' : '显示密码'">
            <i :class="showOldPassword ? 'fas fa-eye-slash' : 'fas fa-eye'"></i>
          </button>
        </div>
      </div>
      <div class="form-group"><label>新密码</label>
        <div class="password-field">
          <input :type="showNewPassword ? 'text' : 'password'" v-model="pw.new" class="input">
          <button type="button" class="password-toggle" @click="showNewPassword = !showNewPassword" :aria-label="showNewPassword ? '隐藏密码' : '显示密码'">
            <i :class="showNewPassword ? 'fas fa-eye-slash' : 'fas fa-eye'"></i>
          </button>
        </div>
      </div>
      <div class="form-group"><label>确认新密码</label>
        <div class="password-field">
          <input :type="showConfirmPassword ? 'text' : 'password'" v-model="pw.confirm" class="input">
          <button type="button" class="password-toggle" @click="showConfirmPassword = !showConfirmPassword" :aria-label="showConfirmPassword ? '隐藏密码' : '显示密码'">
            <i :class="showConfirmPassword ? 'fas fa-eye-slash' : 'fas fa-eye'"></i>
          </button>
        </div>
      </div>
      <button class="btn btn-secondary" @click="changePw" :disabled="changing">{{ changing ? '修改中...' : '修改密码' }}</button>
      <div v-if="pwError" class="error-msg">{{ pwError }}</div>
      <div v-if="pwSuccess" class="success-msg">{{ pwSuccess }}</div>
    </div>

    <div class="section">
      <h3>两步验证</h3>
      <div class="settings-actions">
        <button v-if="!twoFactorEnabled" class="btn btn-primary" @click="enable2FA" :disabled="twofaLoading">启用两步验证</button>
        <button v-else class="btn btn-danger" @click="disable2FA" :disabled="twofaLoading">禁用两步验证</button>
      </div>
    </div>
  </div>
</template>
<script setup>
import { ref, computed, onMounted } from 'vue'
import { storeToRefs } from 'pinia'
import { useUserStore } from '@/stores/user'
import { twoFactorApi } from '@/api'

const userStore = useUserStore()
const { user } = storeToRefs(userStore)
const dirty = ref(false)
const saving = ref(false)
const changing = ref(false)
const twoFactorEnabled = ref(false)
const twofaLoading = ref(false)
const showOldPassword = ref(false)
const showNewPassword = ref(false)
const showConfirmPassword = ref(false)
// 修改密码错误/成功提示
const pwError = ref('')
const pwSuccess = ref('')

const form = ref({ username: '', email: '', full_name: '' })
const pw = ref({ old: '', new: '', confirm: '' })

const userInitials = computed(() => user.value?.username?.slice(0, 2).toUpperCase() || '?')

onMounted(async () => {
  if (user.value) {
    form.value = { username: user.value.username, email: user.value.email, full_name: user.value.full_name || '' }
  }
  try {
    const r = await twoFactorApi.status()
    if (r.success) twoFactorEnabled.value = r.enabled
  } catch (e) { /* ignore */ }
})

async function saveProfile() {
  saving.value = true
  const r = await userStore.updateProfile(form.value)
  saving.value = false
  if (r.success) dirty.value = false
  alert(r.success ? '保存成功' : r.message)
}

async function changePw() {
  // 前置校验：两次输入一致 & 最小长度，避免无效请求
  if (pw.value.new !== pw.value.confirm) {
    pwError.value = '两次输入的密码不一致'
    return
  }
  if (pw.value.new.length < 8) {
    pwError.value = '新密码至少 8 位'
    return
  }
  pwError.value = ''
  changing.value = true
  try {
    const r = await userStore.changePassword(pw.value.old, pw.value.new)
    if (r && (r.success || r.data?.success)) {
      pwSuccess.value = '密码修改成功'
      pwError.value = ''
      // 清空表单，防止敏感凭据残留
      pw.value = { old: '', new: '', confirm: '' }
    } else {
      pwError.value = r?.message || '修改失败'
    }
  } catch (e) {
    // 网络/服务异常时给出可读错误，不弹原生 alert
    pwError.value = '网络错误：' + (e.message || '请稍后重试')
  } finally {
    changing.value = false
  }
}

async function enable2FA() {
  twofaLoading.value = true
  try {
    const r = await twoFactorApi.generate()
    if (r.success) {
      const code = prompt('输入Google Authenticator生成的验证码:\n' + r.otpauth_url)
      if (code) {
        const res = await twoFactorApi.enable(r.secret, code)
        if (res.success) twoFactorEnabled.value = true
        alert(res.success ? '已启用' : res.message)
      }
    }
  } finally { twofaLoading.value = false }
}

async function disable2FA() {
  twofaLoading.value = true
  try {
    const code = prompt('输入验证码以禁用')
    if (code) {
      const res = await twoFactorApi.disable(code)
      if (res.success) twoFactorEnabled.value = false
      alert(res.success ? '已禁用' : res.message)
    }
  } finally { twofaLoading.value = false }
}
</script>
<style scoped>
.page { max-width: 600px; margin: 0 auto; }
.page h1 { font-size: 24px; margin-bottom: 24px; }
.profile-card { background: var(--secondary-bg); border: 1px solid var(--border-color); border-radius: 12px; padding: 30px; margin-bottom: 24px; text-align: center; }
.avatar { width: 80px; height: 80px; margin: 0 auto 16px; border-radius: 50%; background: linear-gradient(135deg, var(--accent-gold), var(--accent-copper)); display: flex; align-items: center; justify-content: center; font-size: 28px; font-weight: 600; color: var(--primary-bg); }
.avatar-section h2 { font-size: 20px; margin-bottom: 4px; }
.avatar-section p { color: var(--text-secondary); margin-bottom: 4px; }
.avatar-section small { color: var(--text-muted); }
.section { background: var(--secondary-bg); border: 1px solid var(--border-color); border-radius: 12px; padding: 20px; margin-bottom: 20px; }
.section h3 { font-size: 16px; margin-bottom: 16px; }
.form-group { margin-bottom: 16px; }
.form-group label { display: block; margin-bottom: 8px; color: var(--text-secondary); font-size: 13px; }
.settings-actions { display: flex; gap: 12px; }
.password-field { position: relative; }
.password-toggle {
  position: absolute; right: 12px; top: 50%; transform: translateY(-50%);
  background: none; border: none; cursor: pointer; color: var(--text-secondary);
}
.error-msg { margin-top: 12px; padding: 10px 12px; background: rgba(239,68,68,0.1); border: 1px solid #ef4444; border-radius: 6px; color: #ef4444; font-size: 13px; }
.success-msg { margin-top: 12px; padding: 10px 12px; background: rgba(76,175,80,0.1); border: 1px solid #4caf50; border-radius: 6px; color: #4caf50; font-size: 13px; }
</style>