function init(){
  console.log("init");
}

function show(){
  console.log("show");
    
  report("status: waiting for response");
  
  var rqstr = getRqStr();
  
  var request = new XMLHttpRequest();
  request.open('GET', rqstr, true);

  request.onreadystatechange = function() {
    if (this.readyState == 4 && this.status == 200) {      
      var resp = this.responseText;
      report("status: parsing response");
      clearInterval(loading_interval);
      parse_response(resp);
      report("<a href='"+rqstr+"'>Download in CSV format</a>");
    }
  };
  
  request.onerror = function() {
    // There was a connection error of some sort
  };
  
  loading_interval = setInterval(loading,100);
  request.send();
}

function getRqStr(){
  var filename = document.getElementById("file").value;
  
  var filter = 0;
  for(var i=0;i<7;i++){
    bit = (document.getElementById("filter_"+i).checked)?1:0;
    filter += (bit<<i);
  }
  
  var start = document.getElementById("start").value;
  var end = document.getElementById("end").value;
  
  var n = end - start;
  
  if (n<0) {
    console.log("Error: Begin > End");
    n = 1;
  }
  
  var rqstr = "read_imu_log.php?file="+filename+"&record="+start+"&nrecords="+n+"&filter="+filter;
  
  report("");
  setTimeout(function(){
    report("<a href='"+rqstr+"'>Download in CSV format</a>");
  },100);
  
  return rqstr;
}

function parse_response(csv){  
  var t = CSVToArray(csv,",");
  
  var result = "";
  
  for(var i=0;i<t.length;i++){
    if (t[i].length>0){
      result += "<tr>";
      for(var j=0;j<t[i].length;j++){
        result += "<td>"+t[i][j]+"</td>";
      }      result += "</tr>";

    }
  }
  document.getElementById("results").innerHTML = "<table>"+result+"</table>";
}

function report(msg){
  document.getElementById("csvlink").innerHTML = msg;
}

var loading_interval;

function loading(){
  console.log("loading");
}

//http://stackoverflow.com/questions/1293147/javascript-code-to-parse-csv-data
function CSVToArray( strData, strDelimiter ){
    // Check to see if the delimiter is defined. If not,
    // then default to comma.
    strDelimiter = (strDelimiter || ",");

    // Create a regular expression to parse the CSV values.
    var objPattern = new RegExp(
        (
            // Delimiters.
            "(\\" + strDelimiter + "|\\r?\\n|\\r|^)" +

            // Quoted fields.
            "(?:\"([^\"]*(?:\"\"[^\"]*)*)\"|" +

            // Standard fields.
            "([^\"\\" + strDelimiter + "\\r\\n]*))"
        ),
        "gi"
        );


    // Create an array to hold our data. Give the array
    // a default empty first row.
    var arrData = [[]];

    // Create an array to hold our individual pattern
    // matching groups.
    var arrMatches = null;


    // Keep looping over the regular expression matches
    // until we can no longer find a match.
    while (arrMatches = objPattern.exec( strData )){

        // Get the delimiter that was found.
        var strMatchedDelimiter = arrMatches[ 1 ];

        // Check to see if the given delimiter has a length
        // (is not the start of string) and if it matches
        // field delimiter. If id does not, then we know
        // that this delimiter is a row delimiter.
        if (
            strMatchedDelimiter.length &&
            strMatchedDelimiter !== strDelimiter
            ){

            // Since we have reached a new row of data,
            // add an empty row to our data array.
            arrData.push( [] );

        }

        var strMatchedValue;

        // Now that we have our delimiter out of the way,
        // let's check to see which kind of value we
        // captured (quoted or unquoted).
        if (arrMatches[ 2 ]){

            // We found a quoted value. When we capture
            // this value, unescape any double quotes.
            strMatchedValue = arrMatches[ 2 ].replace(
                new RegExp( "\"\"", "g" ),
                "\""
                );

        } else {

            // We found a non-quoted value.
            strMatchedValue = arrMatches[ 3 ];

        }


        // Now that we have our value string, let's add
        // it to the data array.
        arrData[ arrData.length - 1 ].push( strMatchedValue );
    }

    // Return the parsed data.
    return( arrData );
}