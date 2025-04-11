document.addEventListener('DOMContentLoaded', function() {
    console.log("DOM Loaded. Initializing listeners...");

    // --- ОБРАБОТЧИК КЛИКОВ ДЛЯ ДЕЙСТВИЙ ИСПОЛНИТЕЛЯ ---
    console.log("Attaching CLICK listener to document body for executor actions...");
    document.body.addEventListener('click', function(event) {

        // 1. Проверяем, является ли цель клика кнопкой SUBMIT
        if (event.target.tagName !== 'BUTTON' || event.target.type !== 'submit') {
            return;
        }
        // 2. Находим ближайшую родительскую форму
        const form = event.target.closest('form');
        // 3. Проверяем форму и action
        if (!form || !form.action || !form.action.includes('executor_actions.php')) {
            return;
        }
        // 4. Проверяем контейнер
        if (!form.closest('.executor-request-list')) {
             return;
        }

        // --- Если все проверки пройдены ---
        console.log("Executor action submit button clicked. Preventing default...");
        event.preventDefault(); // <<--- Отменяем стандартное действие
        event.stopPropagation(); // <<--- Добавляем остановку всплытия

        // --- ИСКУССТВЕННАЯ ЗАДЕРЖКА ---
        // Оборачиваем вызов AJAX в setTimeout, чтобы браузер точно успел обработать preventDefault
        setTimeout(() => {
             console.log("Calling handleExecutorAction after small delay...");
             handleExecutorAction(form); // Вызываем нашу AJAX функцию
        }, 10); // Задержка 10 миллисекунд

        console.log("Prevent default called, returning false...");
        return false; // Возвращаем false для дополнительной гарантии отмены

    }); // Конец обработчика click

    // --- АСИНХРОННАЯ ФУНКЦИЯ ОБРАБОТКИ ДЕЙСТВИЯ ---
    async function handleExecutorAction(form) {
        // ... (КОД ФУНКЦИИ handleExecutorAction БЕЗ ИЗМЕНЕНИЙ) ...
         console.log("handleExecutorAction called for form with action:", form.action);
          const formData = new FormData(form); const actionUrl = String(form.action); const requestItem = form.closest('.list-group-item'); const actionButton = form.querySelector('button[type="submit"]'); const originalButtonHTML = actionButton?.innerHTML; const action = formData.get('action'); const requestId = formData.get('request_id');
          if (!requestItem || !actionButton) { console.error("Required elements not found."); showToast('Ошибка интерфейса.', 'danger'); return; } if (!actionUrl || !actionUrl.includes('executor_actions.php')) { console.error("Invalid action URL:", actionUrl); showToast('Ошибка: Неверный URL.', 'danger'); return;}
          actionButton.disabled = true; actionButton.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>';
          try {
              console.log("Sending fetch request to:", actionUrl); const response = await fetch(actionUrl, { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: formData }); console.log("Fetch response status:", response.status); const result = await response.json(); console.log("Fetch response JSON:", result); if (!response.ok) { throw new Error(result.message || `HTTP error! status: ${response.status}`); }
              console.log("AJAX Success:", result.message); showToast(result.message || 'Действие выполнено.', 'success'); if (result.new_csrf_token) { updateCsrfTokens(result.new_csrf_token); }
              const newStatus = result.new_status; const isRemovingAction = ['take', 'complete', 'cancel'].includes(action);
              if (isRemovingAction) { requestItem.style.transition='opacity 0.3s ease-out, height 0.3s ease-out, padding 0.3s ease-out, margin 0.3s ease-out, border 0.3s ease-out'; requestItem.style.opacity='0'; requestItem.style.height = '0'; requestItem.style.paddingTop = '0'; requestItem.style.paddingBottom = '0'; requestItem.style.marginTop = '0'; requestItem.style.marginBottom = '0'; requestItem.style.borderWidth = '0'; setTimeout(()=>{ requestItem.remove(); updateRequestCounters(); }, 310); }
              else if (newStatus) { updateRequestItemUI(requestItem, newStatus, requestId); }
              else { console.warn("No new status."); if(actionButton){ actionButton.disabled = false; actionButton.innerHTML = originalButtonHTML; } }
              const dropdownToggle = requestItem.querySelector('.dropdown-toggle'); if (dropdownToggle) { const di = bootstrap.Dropdown.getInstance(dropdownToggle); if(di) { try { di.hide(); } catch(e){} } }
          } catch (error) { console.error('Error:', error); showToast(error.message || 'Ошибка.', 'danger'); if(actionButton){ actionButton.disabled = false; actionButton.innerHTML = originalButtonHTML; } }
    } // Конец handleExecutorAction


    // --- Остальные вспомогательные функции (updateRequestItemUI, getStatusBadgeHTML, и т.д.) ---
    // --- Они остаются БЕЗ ИЗМЕНЕНИЙ ---
    function updateRequestItemUI(requestItemElement, newStatus, requestId) { /* ... */ const sBC=requestItemElement.querySelector('.request-status-badge'); if(sBC){sBC.innerHTML=getStatusBadgeHTML(newStatus);}else{console.warn("Status badge not found");} const aC=requestItemElement.querySelector('.request-actions-dropdown > .btn-group'); if(aC){aC.innerHTML=generateActionDropdownHTML(newStatus,requestId);}else{console.warn("Actions container not found");} }
    function getStatusBadgeHTML(status) { /* ... */ let sT=''; let bC='bg-secondary'; switch(status){case 'new':sT='Новая';bC='bg-primary';break; case 'in_progress':sT='В работе';bC='bg-info text-dark';break; case 'paused':sT='Приостановлена';bC='bg-warning text-dark';break; case 'info_requested':sT='Запрос инфо';bC='bg-light text-dark border';break; case 'completed':sT='Выполнена';bC='bg-success';break; case 'cancelled':sT='Отменена';bC='bg-danger';break; default:sT=status?status.charAt(0).toUpperCase()+status.slice(1):'Неизвестно';break;} const eT=sT.replace(/</g,"<").replace(/>/g,">"); return `<span class="badge ${bC}">${eT}</span>`; }
    function generateActionDropdownHTML(status, requestId) { /* ... */ const cTI=document.querySelector('input[name="csrf_token"]'); const cT=cTI?cTI.value:''; if(!cT){console.warn("CSRF token not found");} const cF=`<input type="hidden" name="csrf_token" value="${cT}">`; let mIH=`<li><a class="dropdown-item" href="view_request.php?id=${requestId}">Подробнее</a></li><li><hr class="dropdown-divider"></li>`; if(status==='in_progress'){mIH+=`<li><form method="POST" action="executor_actions.php" class="d-inline w-100">${cF}<input type="hidden" name="action" value="pause"><input type="hidden" name="request_id" value="${requestId}"><button type="submit" class="dropdown-item text-warning">Приостановить</button></form></li><li><form method="POST" action="executor_actions.php" class="d-inline w-100">${cF}<input type="hidden" name="action" value="request_info"><input type="hidden" name="request_id" value="${requestId}"><button type="submit" class="dropdown-item">Запросить информацию</button></form></li><li><form method="POST" action="executor_actions.php" class="d-inline w-100">${cF}<input type="hidden" name="action" value="complete"><input type="hidden" name="request_id" value="${requestId}"><button type="submit" class="dropdown-item text-primary">Завершить</button></form></li>`;} else if(status==='paused'||status==='info_requested'){mIH+=`<li><form method="POST" action="executor_actions.php" class="d-inline w-100">${cF}<input type="hidden" name="action" value="resume"><input type="hidden" name="request_id" value="${requestId}"><button type="submit" class="dropdown-item text-success">Возобновить</button></form></li><li><form method="POST" action="executor_actions.php" class="d-inline w-100">${cF}<input type="hidden" name="action" value="complete"><input type="hidden" name="request_id" value="${requestId}"><button type="submit" class="dropdown-item text-primary">Завершить</button></form></li>`;} mIH+=`<li><hr class="dropdown-divider"></li><li><form method="POST" action="executor_actions.php" class="d-inline w-100">${cF}<input type="hidden" name="action" value="cancel"><input type="hidden" name="request_id" value="${requestId}"><button type="submit" class="dropdown-item text-danger">Отменить заявку</button></form></li>`; const dId=`actionsDropdown_${requestId}_${Date.now()}`; return `<button id="${dId}" type="button" class="btn btn-outline-secondary btn-sm dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">Действия</button><ul class="dropdown-menu dropdown-menu-end" aria-labelledby="${dId}">${mIH}</ul>`; }
    function updateCsrfTokens(newToken) { /* ... */ if(!newToken) return; const cI=document.querySelectorAll('input[name="csrf_token"]'); cI.forEach(i=>{i.value=newToken;}); }
    function showToast(message, type = 'info') { /* ... */ const tC=document.getElementById('toastPlacement');if(!tC){alert(message);return;}const tId='t-'+Date.now();const bC=`bg-${type}`;const tC2=(type==='warning'||type==='info'||type==='light')?'text-dark':'text-white';const tH=`<div id="${tId}" class="toast align-items-center ${tC2} ${bC} border-0" role="alert" aria-live="assertive" aria-atomic="true"><div class="d-flex"><div class="toast-body">${message}</div><button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button></div></div>`;tC.insertAdjacentHTML('beforeend',tH);const tE=document.getElementById(tId);const t=new bootstrap.Toast(tE,{delay:5000});t.show();tE.addEventListener('hidden.bs.toast',()=>tE.remove()); }
    function updateRequestCounters() { /* ... */ const nL=document.getElementById('new-requests-list'); const mL=document.getElementById('my-requests-list'); const nCS=document.getElementById('new-requests-count'); const mCS=document.getElementById('my-requests-count'); if(nL&&nCS){nCS.textContent=nL.querySelectorAll('.list-group-item').length;} if(mL&&mCS){mCS.textContent=mL.querySelectorAll('.list-group-item').length;} }

}); // Конец DOMContentLoaded