const driver=document.getElementById('database_driver');
const fields=document.getElementById('mysqlFields');
function updateDatabaseFields(){if(fields&&driver)fields.hidden=driver.value!=='mysql'}
driver?.addEventListener('change',updateDatabaseFields);
updateDatabaseFields();
document.getElementById('locationButton')?.addEventListener('click',()=>{
    if(!navigator.geolocation){alert('Location services are not available in this browser.');return}
    navigator.geolocation.getCurrentPosition(pos=>{
        document.getElementById('latitude').value=pos.coords.latitude.toFixed(6);
        document.getElementById('longitude').value=pos.coords.longitude.toFixed(6);
        document.getElementById('location_name').value='My location';
    },()=>alert('GaugeIQ could not access your location. You can enter the coordinates manually.'));
});