import { createApp } from 'vue'
import Gallery from './Gallery.vue'
import Upload from './Upload.vue'

const gallery = document.querySelector('#gallery-app')
const upload = document.querySelector('#upload-app')

if (gallery) {
    createApp(Gallery, { gallery: JSON.parse(document.querySelector('#gallery-data').textContent) }).mount(gallery)
}
if (upload) {
    createApp(Upload, { endpoint: upload.dataset.endpoint, back: upload.dataset.back }).mount(upload)
}
