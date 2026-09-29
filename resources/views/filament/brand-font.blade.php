{{-- خط ازانكس للعناوين في لوحات التحكم — لو الملف مش موجود ترجع للخط العادي --}}
<style>
    @font-face { font-family: 'AzanX'; src: url('{{ asset('brand/fonts/azanx-heavy.ttf') }}') format('truetype'); font-weight: 400 900; font-display: swap; }
    .fi-header-heading, .fi-simple-header-heading, .fi-section-header-heading,
    .fi-wi-stats-overview-stat-value, .fi-modal-heading {
        font-family: 'AzanX', inherit; font-weight: 400; letter-spacing: 0;
    }
</style>
