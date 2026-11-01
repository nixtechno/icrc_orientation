{{-- Terms and privacy modals; any link with data-legal-open="terms|privacy" opens one (legal-dialogs.js). Without JavaScript the links go to /terms and /privacy. --}}
@foreach(['terms'=>'Terms & Conditions','privacy'=>'Privacy Policy'] as $doc=>$heading)
<dialog class="legal-dialog" id="legal-{{ $doc }}" data-legal-dialog="{{ $doc }}" aria-labelledby="legal-{{ $doc }}-title">
<div class="legal-dialog-head"><div><span class="eyebrow">ICRC SCODEX 2026</span><h2 id="legal-{{ $doc }}-title">{{ $heading }}</h2></div><button type="button" class="legal-dialog-close" data-legal-close aria-label="Close {{ $heading }}">×</button></div>
<div class="legal-dialog-body legal-copy">@include('legal.'.$doc)</div>
<div class="legal-dialog-foot"><button type="button" data-legal-close>Close</button></div>
</dialog>
@endforeach
<script src="{{ asset('legal-dialogs.js') }}?v=20261101a" defer></script>
