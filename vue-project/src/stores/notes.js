import { defineStore } from 'pinia'
import { ref } from 'vue'
import { notesApi, foldersApi } from '@/api'

export const useNotesStore = defineStore('notes', () => {
  const notes = ref([])
  const currentNote = ref(null)
  const folders = ref([])
  const loading = ref(false)

  async function fetchNotes(filters = {}) {
    loading.value = true
    try {
      const r = await notesApi.list(filters)
      if (r.success) notes.value = r.notes || []
    } catch (e) { console.error('fetchNotes error:', e) }
    loading.value = false
  }

  async function fetchNote(id) {
    const r = await notesApi.get(id)
    if (r.success) currentNote.value = r.note
    return r
  }

  async function createNote(data) {
    const r = await notesApi.create(data)
    if (r.success) await fetchNotes()
    return r
  }

  async function updateNote(id, data) {
    return await notesApi.update(id, data)
  }

  async function deleteNote(id) {
    const r = await notesApi.delete(id)
    if (r.success) notes.value = notes.value.filter(n => n.id !== id)
    return r
  }

  async function searchNotes(keyword) {
    const r = await notesApi.search(keyword)
    if (r.success) notes.value = r.notes || []
    return r
  }

  async function exportNote(id, format = 'markdown') {
    return await notesApi.noteExport(id, format)
  }

  async function batchExport(ids, format = 'markdown') {
    const r = await notesApi.noteExport(ids, format)
    return r
  }

  async function fetchFolders() {
    try {
      const r = await foldersApi.list()
      if (r.success) folders.value = r.folders || []
    } catch (e) { console.error('fetchFolders error:', e) }
  }

  return { notes, currentNote, folders, loading, fetchNotes, fetchNote, createNote, updateNote, deleteNote, searchNotes, exportNote, batchExport, fetchFolders }
})