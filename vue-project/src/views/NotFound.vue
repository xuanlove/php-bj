<template>
  <div class="not-found-page">
    <h1>404</h1>
    <p>页面未找到</p>
    <router-link to="/" class="btn btn-primary">返回首页</router-link>
  </div>
</template>
<style scoped>
.not-found-page { min-height: 100vh; display: flex; flex-direction: column; align-items: center; justify-content: center; text-align: center; }
.not-found-page h1 { font-size: 120px; font-weight: 700; background: linear-gradient(135deg, var(--accent-gold), var(--accent-copper)); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text; margin-bottom: 20px; }
.not-found-page p { font-size: 20px; color: var(--text-secondary); margin-bottom: 30px; }
</style>