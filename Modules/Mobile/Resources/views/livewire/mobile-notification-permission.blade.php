{{-- No chrome of its own. This component exists to raise the operating
     system's own dialog once and to catch the answer the shell injects into
     the page; everything a reader sees about notifications is on the settings
     screen, which reads what this records. --}}
<div
    x-data="beatraxNotificationPermission($wire, @js($grantEvent), @js($askOnLoad))"
    data-testid="notification-permission-bridge"
    class="hidden"
></div>
