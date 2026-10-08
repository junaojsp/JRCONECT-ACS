
(()=>{const root=document.getElementById('login');const particles=root.querySelector('.specks');for(let i=0;i<26;i++){const p=document.createElement('i');p.style.setProperty('--x',(i*37%100)+'%');p.style.setProperty('--speed',(9+i%9)+'s');p.style.setProperty('--delay',(-i*.83)+'s');particles.appendChild(p)}
const pause=root.querySelector('.pause');pause.addEventListener('click',()=>{const paused=root.classList.toggle('paused');pause.textContent=paused?'Retomar animação':'Pausar animação';pause.setAttribute('aria-pressed',String(paused))});
const password=root.querySelector('#password');const show=root.querySelector('.show');show.addEventListener('click',()=>{const visible=password.type==='password';password.type=visible?'text':'password';show.textContent=visible?'Ocultar':'Mostrar';show.setAttribute('aria-pressed',String(visible))});
})();
