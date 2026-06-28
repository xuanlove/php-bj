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
      <div class="form-group"><label>当前密码</label><input type="password" v-model="pw.old" class="input"></div>
      <div class="form-group"><label>新密码</label><input type="password" v-model="pw.new" class="input"></div>
      <div class="form-group"><label>确认新密码</label><input type="password" v-model="pw.confirm" class="input"></div>
      <button class="btn btn-secondary" @click="changePw" :disabled="changing">{{ changing ? '修改中...' : '修改密码' }}</button>
    </div>

    <div class="section">
      <h3>两步验证</h3>
      <div class="settings-actions">
        <button v-if="!twoFactorEnabled" class="btn btn-primary" @click="enable2FA">启用两步验证</button>
        <button v-else class="btn btn-danger" @click="disable2FA">禁用两步验证</button>
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

const form = ref({ username: '', email: '', full_name: '' })
const pw = ref({ old: '', new: '', confirm: '' })

const userInitials = computed(() => user.value?.username?.slice(0, 2).toUpperCase() || '?')

onMounted(async () => {
  // 用户信息可能尚未加载,主动拉取一次
  if (!user.value) {
    try { await userStore.fetchCurrentUser() } catch (e) { /* ignore */ }
  }
  if (user.value) {
    form.value = { username: user.value.username, email: user.value.email, full_name: user.value.full_name || '' }
  }
  // 加载 2FA 状态以决定显示哪个按钮
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
  if (pw.value.new !== pw.value.confirm) { alert('密码不一致'); return }
  changing.value = true
  const r = await userStore.changePassword(pw.value.old, pw.value.new)
  changing.value = false
  if (r.success) { pw.value = { old: '', new: '', confirm: '' }; alert('密码已修改') }
  else alert(r.message)
}

async function enable2FA() {
  const r = await twoFactorApi.generate()
  if (r.success) {
    const code = prompt('输入Google Authenticator生成的验证码:\n' + r.otpauth_url)
    if (code) {
      const res = await twoFactorApi.enable(r.secret, code)
      if (res.success) twoFactorEnabled.value = true
      alert(res.success ? '已启用' : res.message)
    }
  }
}

async function disable2FA() {
  const code = prompt('输入验证码以禁用')
  if (code) {
    const res = await twoFactorApi.disable(code)
    if (res.success) twoFactorEnabled.value = false
    alert(res.success ? '已禁用' : res.message)
  }
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
</style>