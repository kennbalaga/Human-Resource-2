document.addEventListener('DOMContentLoaded', () => {
    const app = document.getElementById('attendanceApp');

    if (!app) {
        return;
    }

    const statusElement = document.getElementById('locationStatus');
    const coordinatesElement = document.getElementById('currentCoordinates');
    const accuracyElement = document.getElementById('locationAccuracy');
    const distanceElement = document.getElementById('officeDistance');
    const mapFallback = document.getElementById('mapFallback');
    const refreshButton = document.querySelector('[data-refresh-location]');
    const attendanceForms = document.querySelectorAll('.attendance-form');
    const clockElement = document.getElementById('liveAttendanceClock');
    const officeLatitude = parseOptionalNumber(app.dataset.officeLatitude);
    const officeLongitude = parseOptionalNumber(app.dataset.officeLongitude);
    const officeRadius = Number(app.dataset.officeRadius || 0);
    const geofenceEnabled = app.dataset.geofenceEnabled === 'true';
    const officeTimezone = app.dataset.officeTimezone || 'Asia/Manila';
    const googleMapsApiKey = app.dataset.googleMapsKey;

    let currentPosition = null;
    let map = null;
    let currentMarker = null;
    let accuracyCircle = null;

    const clockFormatter = new Intl.DateTimeFormat('en-PH', {
        timeZone: officeTimezone,
        hour: 'numeric',
        minute: '2-digit',
        second: '2-digit',
        hour12: true,
    });

    const updateClock = () => {
        if (clockElement) {
            clockElement.textContent = clockFormatter.format(new Date());
        }
    };

    updateClock();
    window.setInterval(updateClock, 1000);

    window.initAttendanceMap = () => {
        const mapElement = document.getElementById('attendanceMap');

        if (!mapElement || !window.google?.maps) {
            return;
        }

        const hasOfficeCoordinates = officeLatitude !== null && officeLongitude !== null;
        const center = hasOfficeCoordinates
            ? { lat: officeLatitude, lng: officeLongitude }
            : { lat: 12.8797, lng: 121.7740 };

        map = new window.google.maps.Map(mapElement, {
            center,
            zoom: hasOfficeCoordinates ? 17 : 5,
            mapTypeControl: false,
            streetViewControl: false,
            fullscreenControl: true,
            gestureHandling: 'cooperative',
        });

        if (hasOfficeCoordinates) {
            new window.google.maps.Marker({
                map,
                position: center,
                title: 'Office location',
                label: {
                    text: 'H',
                    color: '#ffffff',
                    fontWeight: '700',
                },
            });

            new window.google.maps.Circle({
                map,
                center,
                radius: officeRadius,
                strokeColor: '#176b43',
                strokeOpacity: 0.75,
                strokeWeight: 2,
                fillColor: '#32a36b',
                fillOpacity: 0.12,
            });
        }

        mapFallback?.classList.add('is-hidden');

        if (currentPosition) {
            updateMapPosition(currentPosition);
        }
    };

    const setStatus = (state, title, message) => {
        if (!statusElement) {
            return;
        }

        statusElement.classList.remove('location-ready', 'location-error');
        if (state) {
            statusElement.classList.add(`location-${state}`);
        }

        const titleElement = statusElement.querySelector('strong');
        const messageElement = statusElement.querySelector('div span');
        if (titleElement) titleElement.textContent = title;
        if (messageElement) messageElement.textContent = message;
    };

    const applyPositionToForms = (position) => {
        attendanceForms.forEach((form) => {
            form.querySelector('[data-location-field="latitude"]').value = position.latitude;
            form.querySelector('[data-location-field="longitude"]').value = position.longitude;
            form.querySelector('[data-location-field="accuracy"]').value = position.accuracy;
        });
    };

    const updateMapPosition = (position) => {
        if (!map || !window.google?.maps) {
            return;
        }

        const coordinates = { lat: position.latitude, lng: position.longitude };

        if (!currentMarker) {
            currentMarker = new window.google.maps.Marker({
                map,
                position: coordinates,
                title: 'Your current location',
                icon: {
                    path: window.google.maps.SymbolPath.CIRCLE,
                    scale: 8,
                    fillColor: '#3973c4',
                    fillOpacity: 1,
                    strokeColor: '#ffffff',
                    strokeWeight: 3,
                },
            });
        } else {
            currentMarker.setPosition(coordinates);
        }

        if (!accuracyCircle) {
            accuracyCircle = new window.google.maps.Circle({
                map,
                center: coordinates,
                radius: position.accuracy,
                strokeColor: '#3973c4',
                strokeOpacity: 0.35,
                strokeWeight: 1,
                fillColor: '#3973c4',
                fillOpacity: 0.08,
            });
        } else {
            accuracyCircle.setCenter(coordinates);
            accuracyCircle.setRadius(position.accuracy);
        }

        map.panTo(coordinates);
        map.setZoom(18);
    };

    const handlePosition = (geolocationPosition) => {
        currentPosition = {
            latitude: geolocationPosition.coords.latitude,
            longitude: geolocationPosition.coords.longitude,
            accuracy: geolocationPosition.coords.accuracy,
        };

        applyPositionToForms(currentPosition);
        updateMapPosition(currentPosition);

        coordinatesElement.textContent = `${currentPosition.latitude.toFixed(6)}, ${currentPosition.longitude.toFixed(6)}`;
        accuracyElement.textContent = `±${Math.round(currentPosition.accuracy)} m`;

        let distance = null;
        if (officeLatitude !== null && officeLongitude !== null) {
            distance = haversineDistance(
                officeLatitude,
                officeLongitude,
                currentPosition.latitude,
                currentPosition.longitude,
            );
            distanceElement.textContent = distance >= 1000
                ? `${(distance / 1000).toFixed(2)} km`
                : `${Math.round(distance)} m`;
        } else {
            distanceElement.textContent = 'Office coordinates not set';
        }

        const outsideGeofence = geofenceEnabled && distance !== null && distance > officeRadius;
        if (outsideGeofence) {
            setStatus('error', 'Outside the attendance area', `Move within ${officeRadius} meters of the office.`);
        } else {
            setStatus('ready', 'Location verified', geofenceEnabled ? 'You are within the allowed attendance area.' : 'Coordinates are ready to record.');
        }

        return currentPosition;
    };

    const requestCurrentLocation = () => new Promise((resolve, reject) => {
        if (!navigator.geolocation) {
            const error = new Error('This browser does not support geolocation.');
            setStatus('error', 'Location unavailable', error.message);
            reject(error);
            return;
        }

        setStatus(null, 'Finding your location…', 'Please allow location access when prompted.');

        navigator.geolocation.getCurrentPosition(
            (position) => resolve(handlePosition(position)),
            (error) => {
                const messages = {
                    1: 'Location permission was denied. Enable it in your browser settings.',
                    2: 'Your device could not determine its current location.',
                    3: 'The location request timed out. Please try again.',
                };
                const message = messages[error.code] || 'Unable to capture your location.';
                setStatus('error', 'Location verification failed', message);
                reject(new Error(message));
            },
            {
                enableHighAccuracy: true,
                timeout: 15000,
                maximumAge: 0,
            },
        );
    });

    refreshButton?.addEventListener('click', () => {
        refreshButton.disabled = true;
        requestCurrentLocation().finally(() => {
            refreshButton.disabled = false;
        });
    });

    attendanceForms.forEach((form) => {
        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            const submitButton = form.querySelector('button[type="submit"]');
            const submitLabel = submitButton?.querySelector('span');
            const originalLabel = submitLabel?.textContent;

            if (submitButton) submitButton.disabled = true;
            if (submitLabel) submitLabel.textContent = 'Verifying location…';

            try {
                await requestCurrentLocation();
                if (submitLabel) submitLabel.textContent = 'Saving attendance…';
                HTMLFormElement.prototype.submit.call(form);
            } catch {
                if (submitButton) submitButton.disabled = false;
                if (submitLabel && originalLabel) submitLabel.textContent = originalLabel;
            }
        });
    });

    if (googleMapsApiKey) {
        const script = document.createElement('script');
        script.src = `https://maps.googleapis.com/maps/api/js?key=${encodeURIComponent(googleMapsApiKey)}&loading=async&callback=initAttendanceMap&v=weekly&region=PH`;
        script.async = true;
        script.onerror = () => {
            setStatus('error', 'Map failed to load', 'Location capture is still available without the map.');
        };
        document.head.appendChild(script);
    }
});

function parseOptionalNumber(value) {
    if (value === undefined || value === null || value === '') {
        return null;
    }

    const number = Number(value);
    return Number.isFinite(number) ? number : null;
}

function haversineDistance(fromLatitude, fromLongitude, toLatitude, toLongitude) {
    const earthRadius = 6371000;
    const toRadians = (degrees) => degrees * (Math.PI / 180);
    const latitudeDelta = toRadians(toLatitude - fromLatitude);
    const longitudeDelta = toRadians(toLongitude - fromLongitude);
    const fromLatitudeRadians = toRadians(fromLatitude);
    const toLatitudeRadians = toRadians(toLatitude);
    const haversine = Math.sin(latitudeDelta / 2) ** 2
        + Math.cos(fromLatitudeRadians)
        * Math.cos(toLatitudeRadians)
        * Math.sin(longitudeDelta / 2) ** 2;

    return earthRadius * 2 * Math.atan2(Math.sqrt(haversine), Math.sqrt(1 - haversine));
}
