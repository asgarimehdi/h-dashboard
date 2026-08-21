<div {{ $attributes }}>
    <svg class="w-[448px] max-w-none" viewBox="0 0 440 376" fill="none" xmlns="http://www.w3.org/2000/svg">

        {{-- Outer medical cross backplate --}}
        <g style="mix-blend-mode: hard-light">
            <g transform="translate(120 20)">
                <rect x="0" y="40" width="60" height="140" rx="14" fill="#7C3AED"/>
                <rect x="20" y="20" width="20" height="180" rx="10" fill="#7C3AED"/>
                <path d="M0 40h60v140H0z" stroke="#FF750F" stroke-width="2"/>
                <path d="M20 20h20v180H20z" stroke="#FF750F" stroke-width="2"/>
            </g>
        </g>

        {{-- Heart base with gradient layers --}}
        <g class="transition-all delay-300 translate-y-0 opacity-100 duration-750 starting:opacity-0 starting:translate-y-4">
            <path d="M220 320
                     C 150 260, 100 220, 100 165
                     C 100 118, 133 90, 163 90
                     C 190 90, 211 108, 220 130
                     C 229 108, 250 90, 277 90
                     C 307 90, 340 118, 340 165
                     C 340 220, 290 260, 220 320 Z"
                  fill="#F0304E"/>
            <path d="M220 320
                     C 150 260, 100 220, 100 165
                     C 100 118, 133 90, 163 90
                     C 190 90, 211 108, 220 130
                     C 229 108, 250 90, 277 90
                     C 307 90, 340 118, 340 165
                     C 340 220, 290 260, 220 320 Z"
                  stroke="#FF750F" stroke-width="3" stroke-linejoin="round"/>
        </g>

        {{-- Heart sheen --}}
        <g style="mix-blend-mode: hard-light">
            <path d="M163 92
                     C 133 92, 100 120, 100 166
                     C 100 176, 118 142, 172 108
                     C 164 102, 166 92, 163 92 Z"
                  fill="#F8B803" opacity="0.9"/>
        </g>

        {{-- Pulse / ECG line across heart --}}
        <g class="transition-all delay-300 translate-y-0 opacity-100 duration-750 starting:opacity-0 starting:translate-y-4">
            <path d="M60 205 H120 L138 205 155 145 172 270 189 170 205 205 H275 L292 205 308 160 323 235 338 205 H380"
                  fill="none" stroke="#F8B803" stroke-width="5" stroke-linecap="round" stroke-linejoin="round"/>
            <path d="M60 205 H120 L138 205 155 145 172 270 189 170 205 205 H275 L292 205 308 160 323 235 338 205 H380"
                  fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" opacity="0.7"/>
        </g>

        {{-- Monitor / dashboard bars around heart --}}
        <g class="transition-all delay-300 translate-y-0 opacity-100 duration-750 starting:opacity-0 starting:translate-y-4">
            {{-- left bar --}}
            <rect x="70" y="250" width="14" height="60" rx="7" fill="#22D3EE" opacity="0.85"/>
            <rect x="92" y="225" width="14" height="85" rx="7" fill="#22D3EE" opacity="0.55"/>
            <rect x="114" y="195" width="14" height="115" rx="7" fill="#22D3EE" opacity="0.35"/>
            {{-- right bar --}}
            <rect x="312" y="195" width="14" height="115" rx="7" fill="#22D3EE" opacity="0.35"/>
            <rect x="334" y="225" width="14" height="85" rx="7" fill="#22D3EE" opacity="0.55"/>
            <rect x="356" y="250" width="14" height="60" rx="7" fill="#22D3EE" opacity="0.85"/>
        </g>

        {{-- Small medical cross accent at top --}}
        <g class="transition-all delay-300 translate-y-0 opacity-100 duration-750 starting:opacity-0 starting:translate-y-4">
            <circle cx="360" cy="120" r="34" fill="#F8B803"/>
            <circle cx="360" cy="120" r="34" stroke="#FF750F" stroke-width="2"/>
            <rect x="356" y="106" width="8" height="28" rx="3" fill="#fff"/>
            <rect x="346" y="116" width="28" height="8" rx="3" fill="#fff"/>
        </g>

        {{-- Sparkles --}}
        <g style="mix-blend-mode: hard-light">
            <path d="M120 90l5 13 13 5-13 5-5 13-5-13-13-5 13-5z" fill="#22D3EE" opacity="0.8"/>
            <path d="M330 70l4 10 10 4-10 4-4 10-4-10-10-4 10-4z" fill="#F8B803" opacity="0.9"/>
            <path d="M385 260l4 10 10 4-10 4-4 10-4-10-10-4 10-4z" fill="#F0304E" opacity="0.7"/>
        </g>

        {{-- Base dots --}}
        <g fill="#7C3AED" opacity="0.5">
            <circle cx="60" cy="335" r="5"/>
            <circle cx="88" cy="335" r="5"/>
            <circle cx="332" cy="335" r="5"/>
            <circle cx="360" cy="335" r="5"/>
        </g>
    </svg>
</div>