<template>

    <Select v-model="localSelectedClientType" 
            :placeholder="localSelectedClientType ? 'Change client': 'Select client'" 
            not-found-text="No client types found"
            @on-change="$emit('on-change', $event)">
        <Option 
            v-for="(client, i) in clientTypes" 
            :disabled="localDisabled.includes(client.name)"
            :value="client.value" 
            :key="i">{{ client.name }}
        </Option>
    </Select>

</template>
<script>

    export default {
        props:{
            //  This is the selected type e.g) company or user
            selectedClientType:{
                type: String,
                default: ''
            },
            disabled:{
                type: Array,
                default: []
            },
        },
        data(){
            return{
                localSelectedClientType: this.selectedClientType,
                localDisabled: this.disabled,
                clientTypes: [
                    { name: 'Company', value: 'company'},
                    { name: 'Individual', value: 'user'}
                ]
            }
        },
        watch: {

            //  Watch for changes on the selectedClientType
            selectedClientType: {
                handler: function (val, oldVal) {
                    this.localSelectedClientType = val;
                }
            },

            //  Watch for changes on the disabled
            disabled: {
                handler: function (val, oldVal) {
                    this.localDisabled = val;
                }
            }

        },
    }
</script>