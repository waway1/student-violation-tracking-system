// Auto-dismisses success/error alert banners after a short delay.
document.addEventListener("DOMContentLoaded",function(){

const alerts=document.querySelectorAll(".alert-success,.alert-danger");

setTimeout(function(){

alerts.forEach(function(alert){

alert.style.display="none";

});

},3000);

});
