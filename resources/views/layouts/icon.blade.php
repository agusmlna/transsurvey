<svg class="ts-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
@switch($name)
@case('dashboard')
<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>
@break
@case('survey')
<rect x="5" y="4" width="14" height="17" rx="2"/><path d="M9 4V2h6v2M9 9h6M9 13h6M9 17h4"/>
@break
@case('clients')
<circle cx="9" cy="8" r="3"/><path d="M3 21v-3a6 6 0 0 1 12 0v3M16 5a3 3 0 0 1 0 6M21 21v-3a6 6 0 0 0-4-5"/>
@break
@case('mail')
<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 6 9 7 9-7"/>
@break
@case('response')
<path d="M21 11.5a8.5 8.5 0 0 1-8.5 8.5H3l2-5A8.5 8.5 0 1 1 21 11.5Z"/><path d="M8 9h8M8 13h5"/>
@break
@case('bank')
<path d="M4 4h6a3 3 0 0 1 3 3v14a4 4 0 0 0-3-2H4ZM13 7a3 3 0 0 1 3-3h4v15h-4a4 4 0 0 0-3 2"/>
@break
@case('report')
<path d="M4 3v18h17M8 16v-5M13 16V7M18 16V4"/>
@break
@case('follow')
<rect x="4" y="4" width="16" height="17" rx="2"/><path d="M9 4V2h6v2m-7 9 3 3 6-6"/>
@break
@case('logout')
<path d="M9 4H4v16h5M9 12h12m-4-4 4 4-4 4"/>
@break
@case('menu')
<path d="M4 6h16M4 12h16M4 18h16"/>
@break
@case('arrow')
<path d="m7 17 10-10M7 7h10v10"/>
@break
@case('star')
<path d="m12 3 2.8 5.7 6.2.9-4.5 4.4 1.1 6.2-5.6-3-5.6 3 1.1-6.2L3 9.6l6.2-.9Z"/>
@break
@case('check')
<path d="m5 12 4 4L19 6"/>
@break
@case('close')
<path d="m6 6 12 12M18 6 6 18"/>
@break
@endswitch
</svg>
