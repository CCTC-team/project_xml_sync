Feature: E.130.1000 - The system shall allow users with Project Design & Setup rights to use Project XML Sync while keeping REDCap's production restrictions and user-right requirements.

  As a REDCap end user with Project Design & Setup rights
  I want to copy amendments myself
  But only within what REDCap normally allows me to change in a production project

  Scenario: Enable external module from Control Center
    Given I login to REDCap with the user "Test_Admin"
    When I click on the link labeled "Control Center"
    And I click on the link labeled "Manage"
    When I click on the button labeled "Enable a module"
    And I wait for 2 seconds
    And I click on the button labeled "Enable" in the row labeled "Project XML Sync"
    And I wait for 1 second
    And I click on the button labeled "Enable"
    Then I should see "Project XML Sync - v1.0.0"

  Scenario: E.130.1000 - Set up a production project with a Design user
    Given I create a new project named "E.130.1000" by clicking on "New Project" in the menu bar, selecting "Practice / Just for fun" from the dropdown, choosing file "fixtures/cdisc_files/E130_base.xml", and clicking the "Create Project" button
    And I click on the link labeled "Manage"
    When I click on the button labeled "Enable a module"
    And I click on the button labeled "Enable" in the row labeled "Project XML Sync - v1.0.0"
    Then I should see "Project XML Sync - v1.0.0"

    # Test_User1: Project Setup & Design only (no Data Quality / Alerts rights)
    When I click on the link labeled "User Rights"
    And I enter "Test_User1" into the input field labeled "Add with custom rights"
    And I click on the button labeled "Add with custom rights"
    And I check the User Right named "Project Setup & Design"
    And I click on the button labeled "Add user"
    Then I should see "successfully added"

    # Test_User2: no design rights
    When I enter "Test_User2" into the input field labeled "Add with custom rights"
    And I click on the button labeled "Add with custom rights"
    And I click on the button labeled "Add user"
    Then I should see "successfully added"

    When I click on the link labeled "Project Setup"
    And I click on the button labeled "Move project to production"
    And I click on the radio labeled "Keep ALL data saved so far" in the dialog box
    And I click on the button labeled "YES, Move to Production Status" in the dialog box
    Then I should see "Project status: Production"
    And I logout

  Scenario: E.130.1000 - A user without design rights cannot use the module
    Given I login to REDCap with the user "Test_User2"
    When I click on the link labeled "My Projects"
    And I click on the link labeled "E.130.1000"
    Then I should NOT see a link labeled "Project XML Sync"
    And I logout

  Scenario: E.130.1000 - A Design user sees restricted items locked
    Given I login to REDCap with the user "Test_User1"
    When I click on the link labeled "My Projects"
    And I click on the link labeled "E.130.1000"
    And I click on the link labeled "Project XML Sync"
    And I upload a "xml" format file located at "fixtures/cdisc_files/E130_amended.xml", by clicking the button near "Select the Project XML file" to browse for the file, and clicking the button labeled "Upload and compare" to upload the file
    Then I should see "Differences"
    And I should see "You need the \"data_quality_design\" user right"
    And I should see "You need the \"alerts\" user right"

    When I click on the button labeled "Apply selected changes"
    Then I should see "change(s) applied"
    And I should see "Field changes are in Draft Mode"
    When I click on the link labeled "Data Quality"
    Then I should NOT see "AE grade missing"
    And I logout

  Scenario: E.130.1000 - Administrators can restrict the module to administrators only
    Given I login to REDCap with the user "Test_Admin"
    When I click on the link labeled "My Projects"
    And I click on the link labeled "E.130.1000"
    And I click on the link labeled "Manage"
    And I click on the button labeled "Configure"
    And I check the checkbox labeled "Only allow administrators to apply changes"
    And I click on the button labeled "Save"
    Then I should see "Project XML Sync - v1.0.0"
    And I logout

    Given I login to REDCap with the user "Test_User1"
    When I click on the link labeled "My Projects"
    And I click on the link labeled "E.130.1000"
    Then I should NOT see a link labeled "Project XML Sync"
    And I logout

    # Disable external module in Control Center
    Given I login to REDCap with the user "Test_Admin"
    When I click on the link labeled "Control Center"
    And I click on the link labeled "Manage"
    And I click on the button labeled "Disable"
    Then I should see "Disable module?"
    When I click on the button labeled "Disable module"
    Then I should NOT see "Project XML Sync - v1.0.0"
    And I logout

    # Verify no exceptions are thrown in the system
    Given I open Email
    Then I should NOT see an email with subject "REDCap External Module Hook Exception - project_xml_sync"
