(function () {
'use strict';
var uploader = document.querySelector('form[enctype="multipart/form-data"]');
var download = document.querySelector('a[href*="download=csv"]');
if (!uploader || !download) return;
var oldSection = uploader.closest('section');
var box = document.createElement('section');
var title = document.createElement('h2'); title.textContent = '2. Review placements visually'; box.appendChild(title);
var notice = document.createElement('p'); notice.textContent = 'Loading sandbox plan…'; box.appendChild(notice);
oldSection.parentNode.insertBefore(box, oldSection);
var css = document.createElement('style');
css.textContent = '.bi-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(290px,1fr));gap:14px}.bi-card{border:1px solid #c9d1da;border-radius:8px;padding:14px;min-width:0}.bi-card label{display:block;margin:9px 0}.bi-card input:not([type=checkbox]),.bi-card select{box-sizing:border-box;width:100%;padding:8px}.bi-type{background:#e7eef6;padding:12px;border-radius:5px;font-weight:600}.bi-path{overflow-wrap:anywhere;background:#f0f3f6;padding:10px;font-family:monospace}.bi-warn{color:#843d00}.bi-toolbar{border-bottom:1px solid #ddd;padding:10px 0;margin-bottom:15px}.bi-new{color:#145b2c}';
document.head.appendChild(css);
function element(tag,text,parent) { var e=document.createElement(tag);if(text!==undefined)e.textContent=text;if(parent)parent.appendChild(e);return e; }
function parseCSV(text) {
 text=text.replace(/^\uFEFF/,'');var rows=[],row=[],field='',quoted=false,closed=false;
 for(var i=0;i<text.length;i++){
  var ch=text[i];
  if(quoted){if(ch==='"'){if(text[i+1]==='"'){field+='"';i++;}else{quoted=false;closed=true;}}else field+=ch;continue;}
  if(ch==='"'){if(field!==''||closed)throw new Error('Unexpected CSV quote');quoted=true;continue;}
  if(ch===','||ch==='\n'||ch==='\r'){
   row.push(field);field='';closed=false;
   if(ch!==','){if(ch==='\r'&&text[i+1]==='\n')i++;rows.push(row);row=[];}continue;
  }
  if(closed)throw new Error('Unexpected text after CSV quote');field+=ch;
 }
 if(quoted)throw new Error('Unterminated CSV field');
 if(field!==''||row.length){row.push(field);rows.push(row);}return rows;
}
function csvCell(v){return '"'+String(v).replace(/"/g,'""')+'"';}
function component(value,allowEmpty){
 if(allowEmpty&&value==='')return true;
 return value!==''&&value!=='.'&&value!=='..'&&!/[<>:"\\/|?*\x00-\x1f]/.test(value)&&!/[. ]$/.test(value)&&!/^(CON|PRN|AUX|NUL|COM[0-9]|LPT[0-9])(?:\.|$)/i.test(value);
}
function decode(v){if(v[0]==="'"&&(v[1]==="'"||/^[\s]*[=+@-]/.test(v.slice(1))))return v.slice(1);return v;}
function encode(v){return /^[\s]*[=+@-]/.test(v)||v[0]==="'"?"'"+v:v;}
fetch(download.href,{credentials:'same-origin',cache:'no-store'})
.then(function(response){if(!response.ok)throw new Error('Could not download plan');return response.text();})
.then(function(text){
 var matrix=parseCSV(text),headers=matrix.shift();
 var required=['row_id','include','source_relative_path','content_type','category','subcategory','target_relative_path','action','duplicate_decision'];
 if(JSON.stringify(headers)!==JSON.stringify(required))throw new Error('CSV contract differs from expected sandbox version');
 var rows=matrix.map(function(values){if(values.length!==headers.length)throw new Error('CSV field count mismatch');var r={};headers.forEach(function(k,i){r[k]=values[i];});return r;});
 var knownCats=new Set(),knownFolders=new Set(),warnings={};
 rows.forEach(function(r){var p=decode(r.target_relative_path).split('/');if(p.length>=2){knownCats.add(p[0]);knownFolders.add(p.slice(0,-1).join('/'));}});
 document.querySelectorAll('table tr').forEach(function(tr){var cells=tr.querySelectorAll('td');if(cells.length>=4)warnings[cells[0].textContent.trim()]=cells[3].textContent.trim();});
 notice.textContent='Sandbox files only. Choose what to include and where it should go. Folder changes are proposals until validation and approval; editing this screen does not create folders.';
 var limitations=element('p','This patch edits placements only. Artwork previews, PDF first-page rendering, audiobook-cover selection and real USB/device browsing are not implemented here.',box);limitations.className='bi-warn';
 var listId='bi-category-options';var list=element('datalist',undefined,box);list.id=listId;
 knownCats.forEach(function(c){var o=element('option',undefined,list);o.value=c;});
 var toolbar=element('div',undefined,box);toolbar.className='bi-toolbar';
 var bulkLabel=element('label','Apply placement to checked files: ',toolbar);
 var bulkCat=element('input',undefined,bulkLabel);bulkCat.placeholder='Category';bulkCat.setAttribute('list',listId);
 var bulkCourse=element('input',undefined,toolbar);bulkCourse.placeholder='Course / collection (optional)';
 var bulkButton=element('button','Apply to checked files',toolbar);bulkButton.type='button';
 var summary=element('p','',box);var grid=element('div',undefined,box);grid.className='bi-grid';var controls=[];
 var pending=element('p','',box);var submit=element('button','Validate reviewed plan',box);submit.type='button';
 var dirty=false;
 function update(){
  var selected=0,folders=new Set();
  controls.forEach(function(c){
   var target=[c.cat.value,c.course.value,c.filename.value].filter(function(v){return v!=='';}).join('/');
   c.path.textContent=target||'Needs a location';
   var valid=component(c.cat.value,false)&&component(c.course.value,true)&&component(c.filename.value,false);
   c.path.style.color=valid?'':'#a00';
   c.row.include=c.include.checked?'1':'0';c.row.action=c.include.checked?'COPY':'REVIEW';
   c.row.category=encode(c.cat.value);c.row.subcategory=encode(c.course.value);c.row.target_relative_path=encode(target);c.row.duplicate_decision=c.decision.value;
   if(c.include.checked){selected++;if(valid){var parent=[c.cat.value,c.course.value].filter(Boolean).join('/');if(!knownFolders.has(parent))folders.add(parent);}}
  });
  summary.textContent='Selected files: '+selected+' of '+rows.length;
  pending.textContent=folders.size?'Proposed folders (not created): '+Array.from(folders).join(' · '):'No new folder proposals for selected rows.';
  pending.className='bi-new';
 }
 function changed(){dirty=true;update();document.querySelectorAll('button[value="execute"]').forEach(function(b){b.disabled=true;b.title='Validate the edited plan before approving';});submit.textContent='Validate reviewed plan (unsaved changes)';}
 rows.forEach(function(r){
  var card=element('div',undefined,grid);card.className='bi-card';
  var type=element('div',(r.content_type||'file').toUpperCase(),card);type.className='bi-type';
  element('h3',decode(r.source_relative_path).split('/').pop(),card);
  element('p','Source: '+decode(r.source_relative_path),card);
  var includeLabel=element('label',undefined,card);var include=element('input',undefined,includeLabel);include.type='checkbox';include.checked=r.include==='1';includeLabel.appendChild(document.createTextNode(' Include this file'));
  var p=decode(r.target_relative_path).split('/');
  function input(label,value){var l=element('label',label,card);var e=element('input',undefined,l);e.value=value;e.addEventListener('input',changed);return e;}
  var cat=input('Category — choose or enter a proposed new category',decode(r.category));cat.setAttribute('list',listId);
  var course=input('Course / collection — existing name or proposed new name',decode(r.subcategory));
  var filename=input('Destination filename',p[p.length-1]||decode(r.source_relative_path).split('/').pop());
  if(p.length>3)throw new Error('This sandbox UI only supports category/course/file destinations');
  element('small','Current suggestion: '+(decode(r.target_relative_path)||'No suggestion — choose a category'),card);
  var path=element('p','',card);path.className='bi-path';
  var warn=warnings[r.row_id]||'';
  if(warn){var w=element('p','Review warning: '+warn,card);w.className='bi-warn';}
  var dl=element('label','Duplicate decision',card);var decision=element('select',undefined,dl);
  [['REVIEW','Not resolved / no warning'],['SKIP','Skip decision'],['COPY','Copy after review; never overwrite'],['KEEP_BOTH','Keep both at a distinct destination']].forEach(function(pair){var o=element('option',pair[1],decision);o.value=pair[0];});decision.value=r.duplicate_decision;
  var hint=r.content_type==='video'?'Video artwork: supplied artwork or Edutek’s normal thumbnail process.':r.content_type==='pdf'?'PDF first-page thumbnail support is pending.':r.content_type==='image'?'Supporting image: review alongside its primary content.':'Artwork controls are pending.';
  element('small',hint,card);
  include.addEventListener('change',changed);decision.addEventListener('change',changed);
  controls.push({row:r,include:include,cat:cat,course:course,filename:filename,path:path,decision:decision});
 });
 bulkButton.addEventListener('click',function(){
  if(!component(bulkCat.value,false)||!component(bulkCourse.value,true)){alert('Enter a valid category and optional single course-folder name.');return;}
  var selected=controls.filter(function(c){return c.include.checked;});if(!selected.length){alert('Check the files to update first.');return;}
  if(!confirm('Apply this placement to '+selected.length+' checked files?'))return;
  selected.forEach(function(c){c.cat.value=bulkCat.value;c.course.value=bulkCourse.value;});changed();
 });
 submit.addEventListener('click',function(){
  update();
  var invalid=controls.some(function(c){return c.include.checked&&(!component(c.cat.value,false)||!component(c.course.value,true)||!component(c.filename.value,false));});
  if(invalid){alert('Selected files need valid category, optional course and filename fields.');return;}
  var csv=[headers.map(csvCell).join(',')].concat(rows.map(function(r){return headers.map(function(k){return csvCell(r[k]);}).join(',');})).join('\r\n')+'\r\n';
  var data=new FormData();
  uploader.querySelectorAll('input[type="hidden"]').forEach(function(e){data.append(e.name,e.value);});
  data.set('action','upload');data.set('plan',new Blob([csv],{type:'text/csv;charset=utf-8'}),'visual-plan.csv');
  submit.disabled=true;submit.textContent='Submitting for server validation…';
  fetch(uploader.action||window.location.href,{method:'POST',body:data,credentials:'same-origin',redirect:'follow'})
  .then(function(response){if(!response.ok)throw new Error('Server returned HTTP '+response.status);if(new URL(response.url).origin!==location.origin)throw new Error('Unexpected redirect');window.location.assign(response.url);})
  .catch(function(error){submit.disabled=false;submit.textContent='Validate reviewed plan';alert(error.message+' — plan not confirmed submitted. Refresh and inspect job status before retrying.');});
 });
 update();
 var advanced=document.createElement('details');var advancedTitle=element('summary','Advanced: CSV download / upload',advanced);
 while(oldSection.firstChild)advanced.appendChild(oldSection.firstChild);
 oldSection.appendChild(advanced);
})
.catch(function(error){notice.textContent='Visual editor unavailable: '+error.message+'. The original CSV controls remain available.';notice.className='bi-warn';});
}());