// Wraps data tables so they scroll horizontally on small screens.
document.addEventListener("DOMContentLoaded",function(){

const tables=document.querySelectorAll("table");

tables.forEach(function(table){

table.classList.add("table-responsive");

});

});
