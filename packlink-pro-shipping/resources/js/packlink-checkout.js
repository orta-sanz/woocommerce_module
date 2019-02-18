var Packlink = window.Packlink || {};

(function () {
    let hooked = false;
    let hookedUpdate = false;
    let modal, iframe;
    let updateButton;
    let privateData = {
        locations: [],
        endpoint: null,
        selectedLocation: null,
        isCart: false,
        translations: {}
    };

    Packlink.checkout = {};
    Packlink.checkout.init = initialize;
    Packlink.checkout.setIsCart = setIsCart;
    Packlink.checkout.setLocations = setLocations;
    Packlink.checkout.setTranslations = setTranslations;
    Packlink.checkout.setSaveEndpoint = setSaveEndpoint;
    Packlink.checkout.setDropOffAddress = setDropOffAddress;
    Packlink.checkout.setSelectedLocationId = setSelectedLocationId;

    function initialize() {
        iframe = document.getElementById('pl-drop-off-iframe');
        modal = document.getElementById('pl-picker-modal');
        updateButton = document.querySelector("[name='calc_shipping']");

        [].forEach.call(document.getElementsByName('packlink_show_image'), function (item) {
            let parent = item.parentElement;
            let showImage = item.value;
            let imageInput = parent.querySelector('input[name="packlink_image_url"]');
            let button = parent.querySelector('#packlink-drop-off-picker');
            let isDropOff = parent.querySelector('input[name="packlink_is_drop_off"]');

            if (showImage === 'yes' && imageInput) {
                injectImage(imageInput);
            }

            if (isDropOff && button) {
                button.addEventListener('click', function () {
                    iframe.contentWindow.postMessage({type: 'locations', payload: privateData.locations}, '*');
                    modal.style.display = 'block';
                });
            }
        });

        if (modal && iframe && !hooked) {
            window.addEventListener('message', function (event) {
                let selected;

                if (event.data.type === 'ready' && iframe.contentWindow) {
                    iframe.contentWindow.postMessage({type: 'locations', payload: privateData.locations}, '*');
                }

                if (event.data.type === 'select') {
                    privateData.selectedLocation = event.data.payload.id;
                    selected = findLocationById(event.data.payload.id);
                    //document.getElementById('writer').innerHTML = "SELECTED: " + event.data.payload.id;
                    Packlink.ajaxService.post(privateData.endpoint, selected, function () {
                        let button = document.querySelector('#packlink-drop-off-picker');

                        if (button) {
                            button.innerHTML = privateData.translations.changeDropOff;
                        }

                        if (!privateData.isCart) {
                            setHiddenFields(selected);
                        }
                    }, function () {
                    });

                    setDropOffAddress();

                    modal.style.display = 'none';
                }
            });

            hooked = true;

            document.addEventListener('click', function (event) {
                if (modal && event.target.id !== 'packlink-drop-off-picker') {
                    modal.style.display = 'none';
                }
            });
        }

        if (!hookedUpdate && updateButton && jQuery) {
            jQuery(document.body).on('updated_wc_div', initialize);
            hookedUpdate = true;
        }

        document.addEventListener('DOMContentLoaded', setDropOffAddress);
    }

    function setDropOffAddress() {
        if (!privateData.selectedLocation) {
            return;
        }

        let selected = findLocationById(privateData.selectedLocation);

        if (!selected) {
            return;
        }

        setHiddenFields(selected);
        let element = document.querySelector('p.woocommerce-shipping-destination');
        if (!element) {
            element = document.createElement('p');
            element.className = 'woocommerce-shipping-destination';
        }

        element.innerHTML = '<strong>' + privateData.translations.dropOffTitle + '</strong><br/>'
            + [selected.name, selected.address, selected.city].join(', ');

        if (!privateData.isCart) {
            let shippingMethodList = document.querySelector('#shipping_method');
            if (shippingMethodList && shippingMethodList.parentElement) {
                shippingMethodList.parentElement.appendChild(element);
            }
        }
    }

    function setHiddenFields(location) {
        let dropOffId = document.querySelector('input[name="packlink_drop_off_id"]');
        let dropOffExtra = document.querySelector('input[name="packlink_drop_off_extra"]');

        if (dropOffId && dropOffExtra) {
            dropOffId.value = location.id;
            dropOffExtra.value = JSON.stringify(location);
        }
    }

    /**
     * Sets locations.
     *
     * @param {array} locations
     */
    function setLocations(locations) {
        privateData.locations = locations;
    }

    /**
     * Sets save selected endpoint.
     *
     * @param {string} endpoint
     */
    function setSaveEndpoint(endpoint) {
        privateData.endpoint = endpoint;
    }

    /**
     * Sets selected drop-off id.
     *
     * @param {int} locationId
     */
    function setSelectedLocationId(locationId) {
        privateData.selectedLocation = locationId;
    }

    /**
     * Sets is cart flag.
     *
     * @param {boolean} isCart
     */
    function setIsCart(isCart) {
        privateData.isCart = isCart;
    }

    /**
     * Sets package delivery translations.
     *
     * @param {object} translations
     */
    function setTranslations(translations) {
        privateData.translations = translations;
    }

    /**
     * Returns location with provided id.
     *
     * @param {int|string} id
     *
     * @returns {object}
     */
    function findLocationById(id) {
        id = '' + id;

        return privateData.locations.find(function (a) {
            return a.id === id;
        });
    }

    /**
     *
     * @param {HTMLElement} imageSrcInput
     */
    function injectImage(imageSrcInput) {
        let image = document.createElement('img');
        image.src = imageSrcInput.value;
        image.alt = 'carrier image';
        image.className = 'pl-checkout-carrier-image';

        let label = imageSrcInput.parentElement.querySelector('label');
        label.prepend(image);
    }
})();