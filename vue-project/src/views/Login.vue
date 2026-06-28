<template>
  <div class="login-page">
    <div class="login-container">
      <div class="login-header">
        <h1>PHP笔记系统</h1>
        <p>安全、便捷的云笔记管理</p>
      </div>
      <div class="login-form">
        <div class="tabs">
          <button :class="{ active: tab === 'login' }" @click="tab = 'login'">登录</button>
          <button :class="{ active: tab === 'register' }" @click="tab = 'register'">注册</button>
        </div>
        <form @submit.prevent="handleLogin" v-if="tab === 'login'">
          <div class="form-group"><label>用户名</label><input v-model="form.username" class="input" placeholder="请输入用户名" required></div>
          <div class="form-group"><label>密码</label>
            <div class="password-field">
              <input v-model="form.password" :type="showPassword ? 'text' : 'password'" class="input" placeholder="请输入密码" required>
              <button type="button" class="password-toggle" @click="showPassword = !showPassword" :aria-label="showPassword ? '隐藏密码' : '显示密码'">
                <i :class="showPassword ? 'fas fa-eye-slash' : 'fas fa-eye'"></i>
              </button>
            </div>
          </div>
          <button type="submit" class="btn btn-primary btn-block" :disabled="loading">{{ loading ? '登录中...' : '登录' }}</button>
          <div class="error" v-if="error">{{ error }}</div>
        </form>
        <form @submit.prevent="handleRegister" v-if="tab === 'register'">
          <div class="form-group"><label>用户名</label><input v-model="reg.username" class="input" placeholder="3-20位字母数字下划线" required></div>
          <div class="form-group"><label>邮箱</label><input v-model="reg.email" type="email" class="input" placeholder="请输入邮箱" required></div>
          <div class="form-group"><label>密码</label>
            <div class="password-field">
              <input v-model="reg.password" :type="showPassword ? 'text' : 'password'" class="input" placeholder="至少8位" required>
              <button type="button" class="password-toggle" @click="showPassword = !showPassword" :aria-label="showPassword ? '隐藏密码' : '显示密码'">
                <i :class="showPassword ? 'fas fa-eye-slash' : 'fas fa-eye'"></i>
              </button>
            </div>
          </div>
          <div class="form-group"><label>确认密码</label>
            <div class="password-field">
              <input v-model="reg.confirm" :type="showConfirmPassword ? 'text' : 'password'" class="input" placeholder="请再次输入密码" required>
              <button type="button" class="password-toggle" @click="showConfirmPassword = !showConfirmPassword" :aria-label="showConfirmPassword ? '隐藏密码' : '显示密码'">
                <i :class="showConfirmPassword ? 'fas fa-eye-slash' : 'fas fa-eye'"></i>
              </button>
            </div>
          </div>
          <div class="form-group"><label>邀请码</label><input v-model="reg.invitation_code" class="input" placeholder="如有邀请码请填写"></div>
          <button type="submit" class="btn btn-primary btn-block" :disabled="loading">{{ loading ? '注册中...' : '注册' }}</button>
          <div class="error" v-if="error">{{ error }}</div>
        </form>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { useUserStore } from '@/stores/user'
import { authApi } from '@/api'

const router = useRouter()
const route = useRoute()
const userStore = useUserStore()
const tab = ref('login')
const loading = ref(false)
const error = ref('')
const showPassword = ref(false)
const showConfirmPassword = ref(false)
const form = ref({ username: '', password: '' })
const reg = ref({ username: '', email: '', password: '', confirm: '', invitation_code: '' })

async function handleLogin() {
  loading.value = true; error.value = ''
  const r = await userStore.login(form.value)
  loading.value = false
  if (r.success) router.push(route.query.redirect || '/')
  else error.value = r.message
}

async function handleRegister() {
  if (reg.value.password !== reg.value.confirm) { error.value = '两次密码不一致'; return }
  loading.value = true; error.value = ''
  const r = await authApi.register(reg.value)
  loading.value = false
  if (r.success) { tab.value = 'login'; form.value.username = reg.value.username; alert('注册成功，请登录') }
  else error.value = r.message
}
</script>

<style scoped>
.login-page { min-height: 100vh; display: flex; align-items: center; justify-content: center; background: linear-gradient(135deg, var(--primary-bg), var(--secondary-bg)); padding: 20px; }
.login-container { width: 100%; max-width: 400px; }
.login-header { text-align: center; margin-bottom: 30px; }
.login-header h1 { font-size: 28px; font-weight: 600; background: linear-gradient(135deg, var(--accent-gold), var(--accent-copper)); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }
.login-header p { color: var(--text-secondary); font-size: 14px; }
.login-form { background: var(--secondary-bg); border: 1px solid var(--border-color); border-radius: 12px; padding: 30px; }
.tabs { display: flex; margin-bottom: 24px; }
.tabs button { flex: 1; padding: 12px; background: transparent; border: none; border-bottom: 2px solid transparent; color: var(--text-secondary); cursor: pointer; }
.tabs button.active { color: var(--accent-gold); border-bottom-color: var(--accent-gold); }
.form-group { margin-bottom: 16px; }
.form-group label { display: block; margin-bottom: 8px; color: var(--text-secondary); font-size: 13px; }
.btn-block { width: 100%; padding: 14px; margin-top: 10px; }
.error { margin-top: 16px; padding: 12px; background: rgba(244,67,54,0.1); border: 1px solid var(--danger); border-radius: 6px; color: var(--danger); text-align: center; }
.password-field { position: relative; }
.password-toggle {
  position: absolute; right: 12px; top: 50%; transform: translateY(-50%);
  background: none; border: none; cursor: pointer; color: var(--text-secondary);
}
</style>