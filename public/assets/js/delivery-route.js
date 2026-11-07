(() => {
    const app = document.getElementById('delivery-route-app');
    const mapElement = document.getElementById('delivery-map');
    const locationButton = document.getElementById('start-navigation');
    if (!app || !mapElement || !locationButton || !window.L) return;

    const destinationLatitude = Number(app.dataset.latitude);
    const destinationLongitude = Number(app.dataset.longitude);
    if (!Number.isFinite(destinationLatitude) || !Number.isFinite(destinationLongitude)) return;

    const destination = [destinationLatitude, destinationLongitude];
    const map = L.map(mapElement, { zoomControl: false }).setView(destination, 15);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; OpenStreetMap contributors',
    }).addTo(map);
    L.control.zoom({ position: 'bottomright' }).addTo(map);
    L.circleMarker(destination, { radius: 10, color: '#ffffff', weight: 3, fillColor: '#d76345', fillOpacity: 1 })
        .addTo(map)
        .bindTooltip('Customer delivery point', { permanent: true, direction: 'top', offset: [0, -10] });

    const guidanceTitle = document.getElementById('route-instruction');
    const guidanceDetail = document.getElementById('route-detail');
    const routeDistance = document.getElementById('route-distance');
    let driverMarker = null;
    let routeLine = null;
    let watchId = null;
    let lastRoutedPosition = null;
    let routeSteps = [];
    let routeStepIndex = 0;
    let routeInProgress = false;
    let routeLoading = false;

    function distanceBetween(first, second) {
        const toRadians = value => value * Math.PI / 180;
        const latitudeDelta = toRadians(second[0] - first[0]);
        const longitudeDelta = toRadians(second[1] - first[1]);
        const value = Math.sin(latitudeDelta / 2) ** 2
            + Math.cos(toRadians(first[0])) * Math.cos(toRadians(second[0])) * Math.sin(longitudeDelta / 2) ** 2;
        return 6371000 * 2 * Math.atan2(Math.sqrt(value), Math.sqrt(1 - value));
    }

    function describeStep(step) {
        const type = step.maneuver?.type || 'continue';
        const modifier = step.maneuver?.modifier || '';
        const road = step.name ? ` onto ${step.name}` : '';
        if (type === 'arrive') return 'You have arrived at the customer';
        if (type === 'depart') return step.name ? `Head along ${step.name}` : 'Head toward the route';
        if (type === 'roundabout' || type === 'rotary') return `At the roundabout, take exit ${step.maneuver?.exit || ''}${road}`;
        if (type === 'turn' || type === 'end of road') return `Turn ${modifier || 'ahead'}${road}`;
        if (type === 'merge') return `Merge ${modifier}${road}`;
        if (type === 'fork' || type === 'on ramp' || type === 'off ramp') return `Keep ${modifier}${road}`;
        return `Continue${road}`;
    }

    function updateNextStep() {
        if (!routeSteps.length) return;
        const step = routeSteps[Math.min(routeStepIndex, routeSteps.length - 1)];
        guidanceTitle.textContent = describeStep(step);
        guidanceDetail.textContent = step.distance > 0
            ? `${(step.distance / 1000).toFixed(step.distance < 1000 ? 2 : 1)} km · Follow the highlighted route`
            : 'Follow the highlighted route';
    }

    function advanceRouteStep(current) {
        while (routeStepIndex < routeSteps.length - 1) {
            const location = routeSteps[routeStepIndex].maneuver?.location;
            if (!location || distanceBetween(current, [location[1], location[0]]) > 40) break;
            routeStepIndex++;
        }
        updateNextStep();
    }

    async function refreshRoute(position) {
        if (routeLoading) return;
        routeLoading = true;
        const current = [position.coords.latitude, position.coords.longitude];
        const start = `${current[1]},${current[0]}`;
        const end = `${destinationLongitude},${destinationLatitude}`;
        const routeUrl = `https://router.project-osrm.org/route/v1/driving/${start};${end}?overview=full&steps=true&geometries=geojson`;
        guidanceTitle.textContent = 'Finding route...';
        try {
            const response = await fetch(routeUrl, { headers: { Accept: 'application/json' } });
            if (!response.ok) throw new Error('Route service unavailable');
            const data = await response.json();
            const route = data.routes?.[0];
            if (!route) throw new Error('No driving route found');
            if (routeLine) routeLine.remove();
            routeLine = L.geoJSON(route.geometry, { style: { color: '#d76345', weight: 6, opacity: 0.9 } }).addTo(map);
            routeSteps = route.legs?.[0]?.steps || [];
            routeStepIndex = 0;
            routeDistance.textContent = `${(route.distance / 1000).toFixed(1)} km · ${Math.max(1, Math.round(route.duration / 60))} min`;
            guidanceDetail.textContent = 'Route updates when your position changes.';
            map.fitBounds(routeLine.getBounds().pad(0.14));
            lastRoutedPosition = current;
            if (routeSteps.length) advanceRouteStep(current);
            else guidanceTitle.textContent = 'Follow the highlighted route';
            routeLoading = false;
        } catch (error) {
            guidanceTitle.textContent = 'Route unavailable';
            guidanceDetail.textContent = 'The map is still available. Check your connection and try updating your location.';
            routeLoading = false;
        }
    }

    function stopNavigation() {
        if (watchId !== null) navigator.geolocation.clearWatch(watchId);
        watchId = null;
        routeInProgress = false;
        locationButton.textContent = 'Use my location';
        guidanceDetail.textContent = 'Location sharing is stopped.';
    }

    locationButton.addEventListener('click', () => {
        if (routeInProgress) {
            stopNavigation();
            return;
        }
        if (!navigator.geolocation) {
            guidanceTitle.textContent = 'Location unavailable';
            guidanceDetail.textContent = 'This browser cannot share your location. You can still view the destination on the map.';
            return;
        }

        routeInProgress = true;
        locationButton.textContent = 'Stop location sharing';
        guidanceTitle.textContent = 'Waiting for GPS...';
        guidanceDetail.textContent = 'Your location is used only while this route is open. Stop sharing at any time.';
        watchId = navigator.geolocation.watchPosition(position => {
            const current = [position.coords.latitude, position.coords.longitude];
            if (!driverMarker) {
                driverMarker = L.circleMarker(current, { radius: 9, color: '#ffffff', weight: 3, fillColor: '#286c5d', fillOpacity: 1 })
                    .addTo(map)
                    .bindTooltip('Your location', { permanent: true, direction: 'top', offset: [0, -8] });
                map.setView(current, 15);
            } else {
                driverMarker.setLatLng(current);
            }

            if (!lastRoutedPosition || distanceBetween(lastRoutedPosition, current) > 150) {
                refreshRoute(position);
            } else {
                advanceRouteStep(current);
            }
        }, error => {
            routeInProgress = false;
            locationButton.textContent = 'Use my location';
            guidanceTitle.textContent = error.code === error.PERMISSION_DENIED ? 'Location permission denied' : 'Could not get GPS location';
            guidanceDetail.textContent = 'Allow location access in your device settings to get a route from your current position.';
        }, { enableHighAccuracy: true, timeout: 15000, maximumAge: 5000 });
    });

    map.invalidateSize();
})();