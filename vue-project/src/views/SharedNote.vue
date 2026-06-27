<template>
  <div class="shared-note-page">
    <div class="shared-header">
      <h1>PHP笔记系统</h1>
      <p>分享的笔记</p>
    </div>

    <div v-if="loading" class="loading"><i class="fas fa-spinner fa-spin"></i> 加载中...</div>

    <div v-else-if="error" class="error-page">
      <p>{{ error }}</p>
    </div>

    <div v-else-if="needPassword" class="password-form">
      <h2>此分享需要密码访问</h2>
      <input type="password" v-model="password" class="input" placeholder="请输入访问密码" @keyup.enter="verifyPassword">
      <div v-if="pwError" class="error">{{ pwError }}</div>
      <button class="btn btn-primary" @click="verifyPassword">验证</button>
    </div>

    <div v-else-if="note" class="note-content">
      <h2>{{ note.title }}</h2>
      <div class="note-body">{{ note.content }}</div>
      <div class="note-footer">
        <span>由 {{ shareInfo?.username || '未知用户' }} 分享</span>
        <span v-if="shareInfo?.view_count">浏览 {{ shareInfo.view_count }} 次</span>
        <span v-if="shareInfo?.created_at">{{ formatDate(shareInfo.created_at) }}</span>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { useRoute } from 'vue-router'
import { shareApi } from '@/api'
import dayjs from 'dayjs'
import 'dayjs/locale/zh-cn'

const route = useRoute()
const token = route.params.token
const loading = ref(true)
const error = ref('')
const needPassword = ref(false)
const password = ref('')
const pwError = ref('')
const note = ref(null)
const shareInfo = ref(null)

onMounted(async () => {
  try {
    const r = await shareApi.get(token)
    if (!r.success) {
      error.value = r.message || '分享不存在或已过期'
    } else if (r.share?.expired) {
      error.value = '分享链接已过期'
    } else if (r.share?.password) {
      needPassword.value = true
      shareInfo.value = r.share
    } else {
      shareInfo.value = r.share
      await loadContent()
    }
  } catch (e) {
    error.value = '加载失败'
  }
  loading.value = false
})

async function loadContent(pwd) {
  const r = await shareApi.getContent(token, pwd || null)
  if (r.success) {
    note.value = r.content
    needPassword.value = false
  } else if (r.requires_password) {
    needPassword.value = true
  } else {
    error.value = r.message
  }
}

async function verifyPassword() {
  pwError.value = ''
  const r = await shareApi.getContent(token, password.value)
  if (r.success) {
    note.value = r.content
    needPassword.value = false
  } else {
    pwError.value = r.message || '密码错误'
  }
}

function formatDate(d) { return dayjs(d).format('YYYY-MM-DD HH:mm') }
</script>

<style scoped>
.shared-note-page { max-width: 900px; margin: 0 auto; padding: 40px 20px; }
.shared-header { text-align: center; margin-bottom: 40px; }
.shared-header h1 { font-size: 28px; background: linear-gradient(135deg, var(--accent-gold), var(--accent-copper)); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text; }
.shared-header p { color: var(--text-secondary); margin-top: 4px; }
.loading, .error-page { text-align: center; padding: 60px; color: var(--text-muted); }
.error-page { color: var(--danger); }
.password-form { max-width: 400px; margin: 80px auto; text-align: center; }
.password-form h2 { font-size: 20px; margin-bottom: 20px; }
.password-form .input { margin-bottom: 12px; }
.password-form .error { color: var(--danger); margin-bottom: 12px; }
.note-content h2 { font-size: 24px; margin-bottom: 20px; }
.note-body { font-size: 16px; line-height: 1.8; white-space: pre-wrap; }
.note-footer { display: flex; gap: 20px; margin-top: 40px; padding-top: 20px; border-top: 1px solid var(--border-color); font-size: 13px; color: var(--text-muted); }
</style>