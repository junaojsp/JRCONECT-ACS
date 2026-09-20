        </div>

        <!-- Footer -->
        <div class="footer">
            Made by <a href="https://github.com/safrinnetwork/" target="_blank">JRCONECT TELECOM</a>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script src="/assets/js/main.js"></script>
    <?php if (($currentPage ?? '') === 'dashboard'): ?>
    <!-- Scoped to the current overview; other pages keep their existing assets. -->
    <link rel="stylesheet" href="/assets/css/dashboard-chart-fix.css?v=chartfix-1">
    <script src="/assets/js/dashboard-chart-fix.js?v=chartfix-1"></script>
    <?php endif; ?>
    <?php if (basename($_SERVER['SCRIPT_NAME'] ?? '') === 'device-detail.php'): ?>
    <!-- Read-only unified-network identification; no device tasks on page load. -->
    <link rel="stylesheet" href="/assets/css/wifi-network-groups.css?v=groups-1">
    <script src="/assets/js/wifi-network-groups.js?v=groups-1"></script>
    <!-- Presentation layer; existing controls and endpoints remain in place. -->
    <link rel="stylesheet" href="/assets/css/equipment-workspace.css?v=workspace-1">
    <script src="/assets/js/equipment-workspace.js?v=workspace-1"></script>
    <link rel="stylesheet" href="/assets/css/equipment-workspace-fidelity.css?v=fidelity-2">
    <script src="/assets/js/equipment-workspace-fidelity.js?v=fidelity-2"></script>
    <?php endif; ?>
</body>
</html>
