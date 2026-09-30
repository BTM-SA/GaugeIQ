const metric = document.getElementById('metric');
const condition = document.getElementById('condition_type');
const valueField = document.getElementById('valueField');
const degreesField = document.getElementById('degreesField');
const speedField = document.getElementById('speedField');
const fromField = document.getElementById('fromField');
const toField = document.getElementById('toField');

function refreshFields() {
    const m = metric.value;
    const c = condition.value;
    const direction = m === 'wind_direction' && c === 'specific';
    const combined = m === 'wind' && c === 'speed_and_direction';

    degreesField.hidden = !direction;
    valueField.hidden = direction || combined;
    speedField.hidden = !combined;
    fromField.hidden = !combined;
    toField.hidden = !combined;

    [...condition.options].forEach(option => {
        option.hidden =
            m === 'wind_direction'
                ? !['change', 'specific'].includes(option.value)
                : m === 'wind'
                    ? option.value !== 'speed_and_direction'
                    : ['specific', 'speed_and_direction'].includes(option.value);
    });

    if (m === 'wind') {
        condition.value = 'speed_and_direction';
    } else if (m === 'wind_direction' && !['change', 'specific'].includes(condition.value)) {
        condition.value = 'change';
    } else if (m !== 'wind_direction' && ['specific', 'speed_and_direction'].includes(condition.value)) {
        condition.value = 'change';
    }
}

metric.addEventListener('change', refreshFields);
condition.addEventListener('change', refreshFields);
refreshFields();
