/**
 * Markdown 渲染工具
 * 集成 marked + highlight.js，提供带语法高亮的 Markdown→HTML 转换
 */
import { marked } from 'marked'
import hljs from 'highlight.js'
import 'highlight.js/styles/github-dark.css'

// 配置 marked 使用自定义 renderer 实现代码高亮
const renderer = new marked.Renderer()

// 代码块高亮：```lang\n...\n```
renderer.code = function (code, language) {
  // marked v12 可能传 (code, info, escaped) 或 (code, language)
  const codeStr = typeof code === 'string' ? code : (code.text || '')
  const lang = (typeof language === 'string' ? language : (language || '')) || ''
  const langStr = String(lang).trim()

  let highlighted
  try {
    if (langStr && hljs.getLanguage(langStr)) {
      highlighted = hljs.highlight(codeStr, { language: langStr, ignoreIllegal: true }).value
    } else {
      // 无语言或未知语言：自动检测
      highlighted = hljs.highlightAuto(codeStr).value
    }
  } catch (e) {
    highlighted = escapeHtml(codeStr)
  }
  const langLabel = langStr ? ` class="hljs language-${escapeHtml(langStr)}"` : ' class="hljs"'
  return `<pre><code${langLabel}>${highlighted}</code></pre>`
}

// 行内代码高亮：`code`
renderer.codespan = function (code) {
  const codeStr = typeof code === 'string' ? code : (code.text || '')
  let highlighted
  try {
    highlighted = hljs.highlightAuto(codeStr).value
  } catch (e) {
    highlighted = escapeHtml(codeStr)
  }
  return `<code class="hljs inline">${highlighted}</code>`
}

marked.setOptions({
  renderer,
  breaks: true,
  gfm: true
})

function escapeHtml(s) {
  return String(s)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;')
}

/**
 * 将 Markdown 文本渲染为带语法高亮的 HTML
 * @param {string} md Markdown 源码
 * @returns {string} HTML
 */
export function renderMarkdown(md) {
  if (!md) return ''
  try {
    return marked.parse(md)
  } catch (e) {
    console.warn('Markdown 渲染失败:', e)
    return `<pre>${escapeHtml(md)}</pre>`
  }
}

export { marked, hljs }
