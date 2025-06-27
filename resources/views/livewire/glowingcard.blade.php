<?php

use Livewire\Volt\Component;

new class extends Component {
    //
}; ?>
<div>
    <div class="bg-gray-900 text-white min-h-screen flex items-center justify-center p-10">
        <!-- کارت -->
        <div
            class="relative group w-80 h-52 rounded-2xl overflow-hidden p-6 backdrop-blur-md hover:scale-[1.02] transition-transform duration-300 hover:z-20 shadow-xl hover:shadow-white/20 bg-gradient-to-br from-[rgba(32,8,28,0.7)] to-[rgba(20,95,40,0.7)]"

            id="card-1"
            x-data="{
                updateHighlight(event) {
                    const card = document.getElementById('card-1');
                    const highlight = document.getElementById('highlight-1');
                    const rect = card.getBoundingClientRect();
                    const x = event.clientX - rect.left;
                    const y = event.clientY - rect.top;

                    highlight.style.left = `${x}px`;
                    highlight.style.top = `${y}px`;
                    highlight.style.opacity = '0.4';
                    highlight.style.transform = 'translate(-50%, -50%) scale(1)';
                },
                resetHighlight() {
                    const highlight = document.getElementById('highlight-1');
                    highlight.style.opacity = '0';
                    highlight.style.transform = 'translate(-50%, -50%) scale(0.8)';
                }
            }"
            x-on:mousemove="updateHighlight($event)"
            x-on:mouseleave="resetHighlight()"
        >
            <!-- دایره نور -->
            <div
                class="absolute pointer-events-none w-48 h-48 rounded-full bg-gradient-to-r from-white/20 to-transparent opacity-0 transition-all duration-300 ease-out"
                style="filter: blur(20px); transform: translate(-50%, -50%) scale(0.8);"
                id="highlight-1"
            ></div>

            <!-- محتوا -->
            <img
                src="https://n8niostorageaccount.blob.core.windows.net/n8nio-strapi-blobs-prod/assets/miro_logo_94f7214a92.svg"
                alt="Logo"
                class="mb-4 h-10 opacity-60"
            >
            <h4 class="text-lg font-semibold mb-2">“Rebuilt a 4-week AI feature in 10 minutes”</h4>
            <p class="text-sm text-gray-300">Fabian Strunden, AI Product Lead</p>
        </div>
    </div>
</div>
