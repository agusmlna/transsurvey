"use strict";
document.querySelector('[data-toggle-menu]')?.addEventListener('click',()=>document.body.classList.toggle('menu-open'));
document.querySelectorAll('[data-print]').forEach(b=>b.addEventListener('click',()=>window.print()));
document.querySelectorAll('form[data-confirm]').forEach(f=>f.addEventListener('submit',e=>{if(!window.confirm(f.dataset.confirm))e.preventDefault();}));
function toast(text){const el=document.querySelector('#toast');if(el){el.textContent=text;el.hidden=false;setTimeout(()=>el.hidden=true,4500);}}
document.querySelectorAll('[data-copy]').forEach(b=>b.addEventListener('click',async()=>{try{await navigator.clipboard.writeText(b.dataset.copy);toast('Tautan survei disalin.');}catch{window.prompt('Salin tautan ini:',b.dataset.copy);}}));
const editor=document.querySelector('#question-editor');
if(editor){
 const initial=JSON.parse(document.querySelector('#initial-questions').textContent);
 const bank=JSON.parse(document.querySelector('#question-bank').textContent);
 const template=document.querySelector('#question-template');
 function renumber(){[...editor.children].forEach((row,i)=>{row.querySelector('[data-question-number]').textContent='PERTANYAAN '+String(i+1).padStart(2,'0');row.querySelectorAll('[data-field]').forEach(el=>el.name=`questions[${i}][${el.dataset.field}]`);row.querySelector('[data-required-default]').name=`questions[${i}][required]`;row.querySelector('[data-move="-1"]').disabled=i===0;row.querySelector('[data-move="1"]').disabled=i===editor.children.length-1;});}
 function addQuestion(q={}){
  if(editor.children.length>=100){toast('Maksimal 100 pertanyaan.');return;}
  const row=template.content.firstElementChild.cloneNode(true);
  row.querySelectorAll('[data-field]').forEach(el=>{let value=q[el.dataset.field];if(el.type==='checkbox')el.checked=value===undefined?true:(value===true||value===1||value==='1');else el.value=Array.isArray(value)?value.join('\n'):(value??(el.dataset.field==='type'?'rating':el.dataset.field==='category'?'Kualitas layanan':''));});
  const type=row.querySelector('[data-field="type"]');const optionsLabel=row.querySelector('[data-options-label]');
  const toggleOptions=()=>{optionsLabel.hidden=type.value!=='choice';row.querySelector('[data-field="options"]').required=type.value==='choice';};
  type.addEventListener('change',toggleOptions);toggleOptions();
  row.querySelector('[data-remove]').addEventListener('click',()=>{row.remove();renumber();});
  row.querySelectorAll('[data-move]').forEach(b=>b.addEventListener('click',()=>{if(b.dataset.move==='-1'&&row.previousElementSibling)editor.insertBefore(row,row.previousElementSibling);if(b.dataset.move==='1'&&row.nextElementSibling)editor.insertBefore(row.nextElementSibling,row);renumber();}));
  editor.appendChild(row);renumber();
 }
 (Object.values(initial).length?Object.values(initial):[{text:'Seberapa puas Anda terhadap kualitas layanan kami?',category:'Kualitas layanan',type:'rating',required:true}]).forEach(addQuestion);
 document.querySelector('#add-question').addEventListener('click',()=>addQuestion());
 document.querySelector('#bank-select').addEventListener('change',e=>{const q=bank.find(q=>String(q.id)===e.target.value);if(q)addQuestion(q);e.target.value='';});
 editor.closest('form').addEventListener('submit',e=>{if(!editor.children.length){e.preventDefault();window.alert('Tambahkan minimal satu pertanyaan.');}});
}
const responseForm=document.querySelector('.respondent-form');
if(responseForm){
 function update(){let done=0,total=0;responseForm.querySelectorAll('[data-question]').forEach(q=>{const choice=q.querySelector('input[type="radio"]:checked');const value=choice?.value??(q.dataset.type==='text'?q.querySelector('textarea')?.value.trim():'');const low=q.dataset.type==='rating'&&['1','2','3'].includes(value);const comment=q.querySelector('.low-comment');if(comment){comment.hidden=!low;comment.querySelector('textarea').required=low;}if(q.dataset.required==='1'){total++;if(value && (!low || comment.querySelector('textarea').value.trim()))done++;}});const progress=document.querySelector('#survey-progress');if(progress)progress.value=total?Math.round(done/total*100):100;const text=document.querySelector('#survey-progress-text');if(text)text.textContent=`${done} dari ${total} pertanyaan wajib terisi`;}
 responseForm.addEventListener('input',update);update();
 responseForm.addEventListener('submit',e=>{if(responseForm.hasAttribute('data-preview')){e.preventDefault();const msg=document.querySelector('#preview-success');msg.hidden=false;msg.scrollIntoView({behavior:'smooth',block:'center'});}else{responseForm.querySelectorAll('button').forEach(b=>b.classList.add('sending'));}});
}
