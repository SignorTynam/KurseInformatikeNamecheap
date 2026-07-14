import EditorJS from '@editorjs/editorjs';
import Header from '@editorjs/header';
import EditorList from '@editorjs/list';
import Table from '@editorjs/table';
import Quote from '@editorjs/quote';
import Delimiter from '@editorjs/delimiter';

const h = (tag, attrs = {}, children = []) => {
  const element = document.createElement(tag);
  Object.entries(attrs).forEach(([key, value]) => {
    if (key === 'className') element.className = value;
    else if (key === 'text') element.textContent = value;
    else element.setAttribute(key, value);
  });
  (Array.isArray(children) ? children : [children]).filter(Boolean).forEach(child => element.append(child));
  return element;
};

class CalloutTool {
  static get toolbox() { return {title: 'Njoftim', icon: 'ⓘ'}; }
  constructor({data}) { this.data = {...{variant: 'info', title: '', text: ''}, ...data}; }
  render() {
    this.variant = h('select', {className: 'form-select form-select-sm', 'aria-label': 'Lloji i njoftimit'});
    [['info','Informacion'],['success','Sukses'],['warning','Kujdes'],['danger','Rrezik'],['tip','Këshillë']].forEach(([value,label]) => {
      const option = h('option', {value, text: label}); option.selected = this.data.variant === value; this.variant.append(option);
    });
    this.title = h('input', {className: 'form-control', type: 'text', maxlength: '500', placeholder: 'Titulli i njoftimit', 'aria-label': 'Titulli i njoftimit'});
    this.title.value = this.data.title;
    this.text = h('div', {className: 'km-inline-editor', contenteditable: 'true', role: 'textbox', 'aria-label': 'Teksti i njoftimit'});
    this.text.innerHTML = this.data.text;
    return h('div', {className: 'km-callout-editor'}, [this.variant, this.title, this.text]);
  }
  save() { return {variant: this.variant.value, title: this.title.value, text: this.text.innerHTML}; }
}

class CodeTool {
  static get toolbox() { return {title: 'Kod', icon: '&lt;/&gt;'}; }
  constructor({data}) { this.data = {...{language: 'text', code: ''}, ...data}; }
  render() {
    this.language = h('select', {className: 'form-select form-select-sm', 'aria-label': 'Gjuha e kodit'});
    ['text','bash','css','html','javascript','json','php','python','sql','typescript','xml'].forEach(value => {
      const option = h('option', {value, text: value}); option.selected = this.data.language === value; this.language.append(option);
    });
    this.code = h('textarea', {className: 'form-control font-monospace', rows: '8', maxlength: '100000', 'aria-label': 'Kodi'});
    this.code.value = this.data.code;
    return h('div', {className: 'km-code-editor'}, [this.language, this.code]);
  }
  save() { return {language: this.language.value, code: this.code.value}; }
}

class LessonImageTool {
  static get toolbox() { return {title: 'Foto', icon: '▧'}; }
  constructor({data, config}) {
    this.data = {...{mediaId: 0, url: '', caption: '', alt: '', withBorder: false, withBackground: false, stretched: false}, ...data};
    this.config = config;
  }
  render() {
    this.root = h('div', {className: 'km-image-editor'});
    this.preview = h('img', {className: 'km-image-preview', alt: ''});
    if (this.data.url) this.preview.src = this.data.url;
    this.file = h('input', {type: 'file', accept: '.jpg,.jpeg,.png,.gif,.webp,image/jpeg,image/png,image/gif,image/webp', className: 'form-control', 'aria-label': 'Zgjidh foto'});
    this.status = h('div', {className: 'form-text', role: 'status', 'aria-live': 'polite'});
    this.alt = h('input', {type: 'text', maxlength: '500', className: 'form-control', placeholder: 'Teksti alternativ (i detyrueshëm)', 'aria-label': 'Teksti alternativ'}); this.alt.value = this.data.alt;
    this.caption = h('input', {type: 'text', maxlength: '1000', className: 'form-control', placeholder: 'Përshkrimi i fotos', 'aria-label': 'Përshkrimi i fotos'}); this.caption.value = this.data.caption;
    const flags = h('div', {className: 'km-image-flags'});
    [['withBorder','Kornizë'],['withBackground','Sfond'],['stretched','Gjerësi e plotë']].forEach(([key,label]) => {
      const input = h('input', {type: 'checkbox', className: 'form-check-input'}); input.checked = Boolean(this.data[key]); this[key] = input;
      flags.append(h('label', {className: 'form-check'}, [input, document.createTextNode(' ' + label)]));
    });
    this.file.addEventListener('change', () => this.upload());
    this.root.append(this.preview, this.file, this.status, this.alt, this.caption, flags);
    return this.root;
  }
  async upload() {
    const file = this.file.files?.[0]; if (!file) return;
    this.status.textContent = 'Fotoja po ngarkohet…'; this.file.disabled = true;
    const body = new FormData(); body.append('image', file); body.append('draft_token', this.config.draftToken); body.append('course_id', this.config.courseId); body.append('csrf_token', this.config.csrfToken);
    try {
      const response = await fetch(this.config.uploadUrl, {method: 'POST', body, headers: {'X-CSRF-Token': this.config.csrfToken}});
      const result = await response.json();
      if (!response.ok || result.success !== 1) throw new Error(result.message || 'Ngarkimi dështoi.');
      this.data.mediaId = Number(result.file.mediaId); this.data.url = result.file.url; this.preview.src = result.file.url;
      this.status.textContent = 'Fotoja u ngarkua.';
    } catch (error) { this.status.textContent = error.message || 'Ngarkimi dështoi.'; this.file.value = ''; }
    finally { this.file.disabled = false; }
  }
  save() {
    return {mediaId: this.data.mediaId, caption: this.caption.value, alt: this.alt.value, withBorder: this.withBorder.checked, withBackground: this.withBackground.checked, stretched: this.stretched.checked};
  }
  validate(data) { return Number(data.mediaId) > 0 && data.alt.trim() !== ''; }
}

class BlockActionsTune {
  static get isTune() { return true; }
  constructor({api, block}) { this.api = api; this.block = block; }
  render() {
    const root = h('div', {className: 'km-block-actions', role: 'group', 'aria-label': 'Veprimet e bllokut'});
    const button = (label, action) => { const el = h('button', {type: 'button', className: 'cdx-settings-button', 'aria-label': label, title: label, text: label}); el.addEventListener('click', action); return el; };
    root.append(
      button('Lart', () => this.move(-1)),
      button('Poshtë', () => this.move(1)),
      button('Dyfisho', () => this.duplicate()),
      button('Fshi', () => this.remove())
    );
    return root;
  }
  index() { return this.api.blocks.getBlockIndex(this.block.id); }
  move(offset) { const from = this.index(), to = Math.max(0, Math.min(this.api.blocks.getBlocksCount() - 1, from + offset)); if (from !== to) this.api.blocks.move(to, from); }
  async duplicate() { const saved = await this.block.save(); this.api.blocks.insert(saved.tool, structuredClone(saved.data), undefined, this.index() + 1, true); }
  remove() { if (window.confirm('Ta fshijmë këtë bllok?')) this.api.blocks.delete(this.index()); }
}

const root = document.querySelector('[data-lesson-editor]');
if (root) {
  const form = root.closest('form');
  const initial = JSON.parse(document.getElementById('lesson-editor-initial')?.textContent || '[]');
  const config = {
    csrfToken: root.dataset.csrfToken,
    courseId: root.dataset.courseId,
    draftToken: root.dataset.draftToken,
    uploadUrl: root.dataset.uploadUrl,
  };
  const dirty = document.getElementById('lesson-unsaved');
  let isDirty = false;
  const markDirty = value => { isDirty = value; if (dirty) { dirty.hidden = !value; dirty.textContent = value ? 'Ndryshime të paruajtura' : ''; } };
  const editor = new EditorJS({
    holder: root,
    placeholder: 'Shkruani përmbajtjen e leksionit…',
    data: {blocks: initial},
    autofocus: false,
    tools: {
      heading: {class: Header, config: {levels: [2,3,4], defaultLevel: 2}, tunes: ['blockActions']},
      list: {class: EditorList, inlineToolbar: true, config: {maxLevel: 1}, tunes: ['blockActions']},
      table: {class: Table, inlineToolbar: true, config: {rows: 2, cols: 2, maxRows: 30, maxCols: 20}, tunes: ['blockActions']},
      image: {class: LessonImageTool, config, tunes: ['blockActions']},
      quote: {class: Quote, inlineToolbar: true, tunes: ['blockActions']},
      callout: {class: CalloutTool, tunes: ['blockActions']},
      code: {class: CodeTool, tunes: ['blockActions']},
      delimiter: {class: Delimiter, tunes: ['blockActions']},
      blockActions: BlockActionsTune,
    },
    tunes: ['blockActions'],
    onChange: () => markDirty(true),
  });

  const insertDefaults = {
    paragraph: {}, heading: {text: '', level: 2}, list: {style: 'unordered', items: []}, table: {withHeadings: true, content: [['',''],['','']]},
    image: {}, quote: {text: '', caption: '', alignment: 'left'}, callout: {variant: 'info', title: '', text: ''}, code: {language: 'text', code: ''}, delimiter: {},
  };
  document.querySelectorAll('[data-insert-block]').forEach(button => button.addEventListener('click', async () => {
    await editor.isReady; const type = button.dataset.insertBlock; editor.blocks.insert(type, structuredClone(insertDefaults[type] || {}), undefined, undefined, true); markDirty(true);
  }));

  const saveIntoField = async () => {
    const output = await editor.save();
    document.getElementById('blocks_json').value = JSON.stringify({blocks: output.blocks});
    return output;
  };
  form?.addEventListener('submit', async event => {
    if (form.dataset.editorReady === '1') { markDirty(false); return; }
    event.preventDefault();
    try {
      await saveIntoField(); form.dataset.editorReady = '1'; markDirty(false);
      if (event.submitter) form.requestSubmit(event.submitter); else form.requestSubmit();
    }
    catch (error) { document.getElementById('lesson-editor-error').textContent = 'Kontrolloni blloqet e shënuara dhe provoni përsëri.'; }
  });
  document.getElementById('lesson-preview-button')?.addEventListener('click', async () => {
    try {
      const output = await saveIntoField();
      const response = await fetch(root.dataset.previewUrl, {method: 'POST', headers: {'Content-Type':'application/json','X-CSRF-Token':config.csrfToken}, body: JSON.stringify({blocks: output.blocks})});
      const result = await response.json(); if (!response.ok || !result.success) throw new Error(result.message || 'Pamja paraprake dështoi.');
      document.getElementById('lesson-preview-content').innerHTML = result.html; document.getElementById('lesson-preview-dialog').showModal();
    } catch (error) { document.getElementById('lesson-editor-error').textContent = error.message; }
  });
  document.getElementById('lesson-preview-close')?.addEventListener('click', () => document.getElementById('lesson-preview-dialog').close());
  window.addEventListener('beforeunload', event => { if (isDirty) { event.preventDefault(); event.returnValue = ''; } });
}
